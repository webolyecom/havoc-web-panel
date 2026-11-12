<?php
declare(strict_types=1);

namespace Havoc\Ctl;

final class NginxDriver implements WebServer
{
    private const DIR = '/etc/nginx/conf.d';
    private const CUSTOM = '/etc/nginx/havoc-custom';

    public function type(): string { return 'nginx'; }
    public function service(): string { return 'nginx'; }

    public function installed(): bool
    {
        return is_file('/usr/sbin/nginx') && is_file('/lib/systemd/system/nginx.service');
    }

    public function version(): string
    {
        [, , $err] = Sys::run(['/usr/sbin/nginx', '-v']);
        return preg_match('#nginx/([0-9.]+)#', $err, $m) ? $m[1] : '';
    }

    public function prepare(): void
    {
        @unlink('/etc/nginx/sites-enabled/default');               // the Debian welcome site would take port 80 for unknown hosts
        is_dir(self::CUSTOM) || mkdir(self::CUSTOM, 0755, true);
    }

    public function start(): void
    {
        Sys::ok(['systemctl', 'enable', 'nginx']);
        Sys::ok(['systemctl', 'restart', 'nginx']);
    }

    public function stop(): void
    {
        Sys::run(['systemctl', 'disable', '--now', 'nginx']);
    }

    public function apply(array $vh): void
    {
        $file = self::DIR . '/havoc-' . $vh['domain'] . '.conf';
        $cust = self::CUSTOM . '/' . $vh['domain'] . '.conf';
        $old  = is_file($file) ? file_get_contents($file) : null;
        $oldC = is_file($cust) ? file_get_contents($cust) : null;
        $custom = trim((string)($vh['nginx_custom'] ?? ''));
        if ($custom !== '') {
            Sys::write($cust, "# Custom rules for {$vh['domain']} (edited in Havoc WEB Panel)\n" . $custom . "\n", 0644);
        } else {
            @unlink($cust);
        }
        Sys::write($file, $this->render($vh));
        [$c, , $e] = Sys::run(['/usr/sbin/nginx', '-t']);
        if ($c !== 0) {
            $old === null ? @unlink($file) : Sys::write($file, $old);
            $oldC === null ? @unlink($cust) : Sys::write($cust, $oldC, 0644);
            $msg = trim(preg_replace('/^nginx: (the configuration file .* syntax is ok|configuration file .* test failed)$/m', '', $e));
            throw new \RuntimeException('nginx config test failed: ' . str_replace(self::CUSTOM . '/', '', $msg));
        }
    }

    public function remove(string $domain): void
    {
        @unlink(self::DIR . '/havoc-' . $domain . '.conf');
        @unlink(self::CUSTOM . '/' . $domain . '.conf');
    }

    public function reload(): void
    {
        Sys::ok(['systemctl', 'reload', 'nginx']);
    }

    private function http2Modern(): bool
    {
        return version_compare($this->version() ?: '0', '1.25.1', '>=');
    }

    private function render(array $vh): string
    {
        $d      = $vh['domain'];
        $names  = implode(' ', array_merge([$d], $vh['aliases'] ?? []));
        $root   = $vh['docroot'];
        $sock   = '/run/havoc-fpm/' . $vh['id'] . '.sock';
        $ssl    = !empty($vh['ssl']['cert']);
        $logDir = '/var/log/havoc/domains';
        $access = $vh['logs'] ? "access_log $logDir/$d.access.log;" : 'access_log off;';
        $waf    = !empty($vh['waf']) ? "modsecurity on;\n    modsecurity_rules_file /etc/nginx/modsec/main.conf;" : '';

        $redir = '';
        $whole = null;
        foreach ($vh['redirects'] ?? [] as $r) {
            $src = $r['source'];
            $tg  = $r['target'];
            $c   = (int)$r['code'];
            if ($src === '/') {
                $whole = "    location / { return $c " . rtrim($tg, '/') . "\$request_uri; }\n";
            } elseif (str_ends_with($src, '/*')) {
                $redir .= '    location ^~ ' . substr($src, 0, -1) . " { return $c $tg; }\n";
            } else {
                $redir .= "    location = $src { return $c $tg; }\n";
            }
        }
        $custom = trim((string)($vh['nginx_custom'] ?? ''));
        $useCustom = $custom !== '' && empty($vh['suspended']);
        $rootLoc = $useCustom && NginxCustom::definesRoot($custom) ? '' : "    location / { try_files \$uri \$uri/ /index.php?\$args; }\n";
        $locs = $whole ?? $rootLoc . "    location ~ \.php\$ {\n        include snippets/fastcgi-php.conf;\n        fastcgi_pass unix:$sock;\n    }\n";
        if ($useCustom && $whole === null) {
            $locs = "    include " . self::CUSTOM . "/$d.conf;\n" . $locs;
        }

        if (!empty($vh['suspended'])) {
            $redir = '';
            $locs = "    location / { default_type text/plain; return 503 'Temporarily unavailable: the hosting resource quota of this account is exceeded.'; }\n";
        }

        $body = <<<NGX
    root $root;
    index index.php index.html index.htm;
    $access
    error_log $logDir/$d.error.log warn;
    client_max_body_size 128m;
    $waf

    location ^~ /.well-known/acme-challenge/ { root /var/www/havoc-acme; default_type text/plain; try_files \$uri =404; }
    location ~ /\.(?!well-known) { deny all; }
$redir$locs
NGX;

        $out = "# Managed by Havoc WEB Panel - do not edit\nserver {\n    listen 80;\n    listen [::]:80;\n    server_name $names;\n";
        if ($ssl && !empty($vh['force_https'])) {
            $out .= "    location ^~ /.well-known/acme-challenge/ { root /var/www/havoc-acme; default_type text/plain; try_files \$uri =404; }\n";
            $out .= "    location / { return 301 https://\$host\$request_uri; }\n}\n";
        } else {
            $out .= "$body\n}\n";
        }
        if ($ssl) {
            $modern = $this->http2Modern();
            $out .= "server {\n    listen 443 ssl" . ($modern ? '' : ' http2') . ";\n    listen [::]:443 ssl" . ($modern ? '' : ' http2') . ";\n";
            if ($modern) {
                $out .= "    http2 on;\n";
            }
            $out .= "    server_name $names;\n";
            $out .= "    ssl_certificate {$vh['ssl']['cert']};\n    ssl_certificate_key {$vh['ssl']['key']};\n";
            $out .= "    ssl_protocols TLSv1.2 TLSv1.3;\n    ssl_session_cache shared:SSL:10m;\n    ssl_session_timeout 1d;\n";
            if (!empty($vh['hsts'])) {
                $out .= "    add_header Strict-Transport-Security \"max-age=31536000; includeSubDomains\" always;\n";
            }
            $out .= "$body\n}\n";
        }
        return $out;
    }
}
