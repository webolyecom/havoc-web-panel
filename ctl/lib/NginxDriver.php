<?php
declare(strict_types=1);

namespace Havoc\Ctl;

final class NginxDriver implements WebServer
{
    private const DIR = '/etc/nginx/conf.d';
    private const CUSTOM = '/etc/nginx/havoc-custom';
    private const DEFAULT = '/etc/nginx/conf.d/havoc-000-default.conf';
    private const TUNING = '/etc/nginx/conf.d/havoc-00-tuning.conf';
    private const MAIN = '/etc/nginx/nginx.conf';
    /** http-level directives the panel sets in TUNING; active copies in nginx.conf are commented out (duplicates fail) */
    private const MOVED = 'gzip|gzip_comp_level|gzip_min_length|gzip_vary|gzip_proxied|gzip_types|gzip_buffers|gzip_http_version|keepalive_timeout|keepalive_requests|server_tokens|client_body_timeout|send_timeout|client_max_body_size|open_file_cache|open_file_cache_valid|open_file_cache_min_uses|open_file_cache_errors|fastcgi_read_timeout';

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
        $this->ensureDefault();
    }

    /**
     * Catch-all server for host names that no site claims (the server's IP, a domain whose DNS points here but is not added,
     * HTTPS for a site that has no certificate yet, Cloudflare connecting with an unknown SNI). Without it Nginx answers such
     * requests with the first site in alphabetical order, so every unknown name showed that site's files.
     */
    private function ensureDefault(): void
    {
        // site preview from the panel (Preview.php): a request carrying the secret header counts as HTTPS for PHP, so a
        // site without a certificate yet does not redirect to https:// in a loop; it is never served from the page cache
        $secret = Preview::secret();
        $pv = "# Managed by Havoc WEB Panel - site preview (secret: keep 0640)\n"
            . "map \$http_x_havoc_preview \$havoc_https { default \$https; \"~^$secret\$\" on; }\n"
            . "map \$http_x_havoc_preview \$havoc_scheme { default \$scheme; \"~^$secret\$\" https; }\n"
            . "map \$http_x_havoc_preview \$havoc_preview { default \"\"; \"~^$secret\$\" 1; }\n";
        if (@file_get_contents('/etc/nginx/conf.d/havoc-00-preview.conf') !== $pv) {
            Sys::write('/etc/nginx/conf.d/havoc-00-preview.conf', $pv, 0640);
        }
        $page = '/var/www/havoc-default';
        is_dir($page) || mkdir($page, 0755, true);
        if (!is_file("$page/index.html")) {
            Sys::write("$page/index.html", "<!doctype html><meta charset=utf-8><meta name=viewport content=\"width=device-width\"><title>No website here</title>"
                . "<body style=\"font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;color:#334155\">"
                . "<div style=\"text-align:center\"><h1 style=\"font-size:22px\">No website is configured for this address</h1>"
                . "<p>If this is your domain, add it in the hosting panel.</p></div>\n", 0644);
        }
        $conf = <<<NGX
# Managed by Havoc WEB Panel - catch-all for host names no site claims
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    access_log off;
    location ^~ /.well-known/acme-challenge/ { root /var/www/havoc-acme; default_type text/plain; try_files \$uri =404; }
    location = /index.html { root $page; internal; }
    error_page 404 /index.html;
    location / { return 404; }
}
server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;
    access_log off;
    ssl_reject_handshake on;
}

NGX;
        if (is_file(self::DEFAULT) && file_get_contents(self::DEFAULT) === $conf) {
            return;
        }
        $old = is_file(self::DEFAULT) ? file_get_contents(self::DEFAULT) : null;
        Sys::write(self::DEFAULT, $conf, 0644);
        if (Sys::run(['/usr/sbin/nginx', '-t'])[0] !== 0) {
            // e.g. another default_server defined by hand: keep the server working, sites still get their own blocks
            $old === null ? @unlink(self::DEFAULT) : Sys::write(self::DEFAULT, $old, 0644);
        }
    }

    /**
     * Server → Web server → Settings. Worker settings go into nginx.conf (main/events context), everything else into
     * conf.d/havoc-00-tuning.conf (http context, read before the sites). Rolled back when `nginx -t` fails.
     */
    /** Set by tune(): the cache zone changed, which Nginx only takes over on a restart (a reload keeps the old zone). */
    public bool $needsRestart = false;

    public function tune(array $t): void
    {
        $oldMain = (string)file_get_contents(self::MAIN);
        $oldTune = is_file(self::TUNING) ? (string)file_get_contents(self::TUNING) : null;
        $m = $oldMain;
        $set = static function (string $re, string $line, string $insertAfter) use (&$m): void {
            $n = 0;
            $m = (string)preg_replace($re, $line, $m, 1, $n);
            if ($n === 0) {
                $m = (string)preg_replace($insertAfter, '$0' . "\n" . $line, $m, 1);
            }
        };
        $wc = (int)$t['worker_connections'];
        $set('/^[ \t]*worker_processes[ \t]+[^;]*;/m', "worker_processes {$t['worker_processes']};", '/^[ \t]*user[ \t]+[^;]*;/m');
        $set('/^[ \t]*worker_rlimit_nofile[ \t]+[^;]*;/m', 'worker_rlimit_nofile ' . max(8192, $wc * 2) . ';', '/^[ \t]*worker_processes[ \t]+[^;]*;/m');
        $set('/^([ \t]*)worker_connections[ \t]+[^;]*;/m', "\tworker_connections $wc;", '/^[ \t]*events[ \t]*\{/m');
        // the panel's http-level directives replace active ones in nginx.conf (each line of a multi-line one is commented)
        $m = (string)preg_replace_callback('/^[ \t]*(?:' . self::MOVED . ')[ \t][^;]*;/m',
            static fn($x) => (string)preg_replace('/^/m', '# [moved to conf.d/havoc-00-tuning.conf] ', $x[0]), $m);
        // the cache zone always exists (see tuningConf), so its folder too
        is_dir(WebTuning::NGINX_CACHE_DIR) || mkdir(WebTuning::NGINX_CACHE_DIR, 0700);
        @chown(WebTuning::NGINX_CACHE_DIR, 'www-data');               // the workers write it
        @chgrp(WebTuning::NGINX_CACHE_DIR, 'www-data');
        $new = self::tuningConf($t);
        $zone = static fn(?string $c) => preg_match('/^fastcgi_cache_path .*$/m', (string)$c, $x) ? $x[0] : '';
        $this->needsRestart = $zone($oldTune) !== $zone($new);
        Sys::write(self::MAIN, $m, 0644);
        Sys::write(self::TUNING, $new, 0644);
        [$c, , $e] = Sys::run(['/usr/sbin/nginx', '-t']);
        if ($c !== 0) {
            Sys::write(self::MAIN, $oldMain, 0644);
            $oldTune === null ? @unlink(self::TUNING) : Sys::write(self::TUNING, $oldTune, 0644);
            throw new \RuntimeException('nginx config test failed, nothing was changed: '
                . trim((string)preg_replace(['/^nginx: (the configuration file .* syntax is ok|configuration file .* test failed)$/m',
                    '#^(nginx: )?(\d{4}/\d\d/\d\d \d\d:\d\d:\d\d )?\[emerg\] (\d+\#\d+: )?#m'], '', $e)));
        }
    }

    private static function tuningConf(array $t): string
    {
        $o = "# Managed by Havoc WEB Panel (Server > Web server > Settings) - do not edit\n"
            . "server_tokens off;\n"
            . "keepalive_timeout {$t['keepalive_timeout']}s;\nkeepalive_requests {$t['keepalive_requests']};\n"
            . "client_body_timeout {$t['timeout']}s;\nsend_timeout {$t['timeout']}s;\n"
            . "client_max_body_size {$t['client_max_body_size']}m;\nfastcgi_read_timeout {$t['fastcgi_read_timeout']}s;\n\n";
        $o .= $t['gzip']
            ? "gzip on;\ngzip_comp_level {$t['gzip_level']};\ngzip_min_length 1024;\ngzip_vary on;\ngzip_proxied any;\n"
              . "gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml application/rss+xml "
              . "application/atom+xml application/ld+json image/svg+xml font/ttf font/otf application/vnd.ms-fontobject;\n\n"
            : "gzip off;\n\n";
        $o .= $t['open_file_cache']
            ? "open_file_cache max=10000 inactive=60s;\nopen_file_cache_valid 120s;\nopen_file_cache_min_uses 2;\nopen_file_cache_errors off;\n\n"
            : "open_file_cache off;\n\n";
        // page cache (FastCGI cache). The zone is always defined: turning the cache off must not break sites whose vhost still
        // uses it until they are re-rendered; its shared memory is only touched when entries are stored.
        $o .= 'fastcgi_cache_path ' . WebTuning::NGINX_CACHE_DIR . " levels=1:2 keys_zone=havoc:{$t['cache_zone_mb']}m max_size={$t['cache_max_mb']}m"
            . " inactive={$t['cache_inactive']}m use_temp_path=off;\n";
        // never cached and never served from the cache: other methods than GET/HEAD, logged-in/session cookies, admin and cart pages
        $o .= "map \$request_method \$havoc_nc_method { default 1; GET 0; HEAD 0; }\n"
            . "map \$http_cookie \$havoc_nc_cookie { default 0; \"~*(" . WebTuning::NOCACHE_COOKIES . ")\" 1; }\n"
            . "map \$request_uri \$havoc_nc_uri { default 0; \"~*(" . WebTuning::NOCACHE_URIS . ")\" 1; }\n";
        return $o;
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
        $this->ensureDefault();
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
        $moved  = empty($vh['suspended']) ? ($vh['redirect_hosts'] ?? []) : [];   // suspended: the old names show the notice too
        $names  = implode(' ', array_merge([$d], array_diff($vh['aliases'] ?? [], $moved)));
        $root   = $vh['docroot'];
        $sock   = '/run/havoc-fpm/' . $vh['id'] . '.sock';
        $ssl    = !empty($vh['ssl']['cert']);
        $logDir = '/var/log/havoc/domains';
        $access = $vh['logs'] ? "access_log $logDir/$d.access.log;" : 'access_log off;';
        $waf    = !empty($vh['waf']) ? "modsecurity on;\n    modsecurity_rules_file /etc/nginx/modsec/main.conf;" : '';
        $t      = WebTuning::get('nginx');
        $ttl    = $t['cache'] && is_file(self::TUNING) ? WebTuning::siteTtl((int)$vh['id']) : 0;
        $cache  = $ttl > 0
            ? "        fastcgi_cache havoc;\n        fastcgi_cache_key \"\$scheme\$request_method\$host\$request_uri\";\n"
              . "        fastcgi_cache_valid 200 301 302 {$ttl}s;\n"
              . "        fastcgi_cache_bypass \$havoc_nc_method \$havoc_nc_cookie \$havoc_nc_uri \$havoc_preview;\n        fastcgi_no_cache \$havoc_nc_method \$havoc_nc_cookie \$havoc_nc_uri \$havoc_preview;\n"
              . "        fastcgi_cache_use_stale error timeout updating http_500 http_503;\n        fastcgi_cache_background_update on;\n        fastcgi_cache_lock on;\n"
            : '';
        $cacheHdr = $ttl > 0 ? "add_header X-Havoc-Cache \$upstream_cache_status;   # HIT / MISS / BYPASS / EXPIRED\n    " : '';
        $static = (int)$t['static_expires'] > 0
            ? "    location ~* \\.(?:css|js|mjs|jpe?g|png|gif|webp|avif|ico|svg|woff2?|ttf|otf|eot|mp4|webm|mp3|ogg|pdf)\$ {\n"
              . "        expires {$t['static_expires']}d;\n        access_log off;\n        try_files \$uri \$uri/ /index.php?\$args;\n    }\n"
            : '';

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
        $pvParams = "        fastcgi_param HTTPS \$havoc_https if_not_empty;\n        fastcgi_param REQUEST_SCHEME \$havoc_scheme;\n        fastcgi_param HTTP_X_HAVOC_PREVIEW \"\";\n";
        $locs = $whole ?? $rootLoc . $static . "    location ~ \.php\$ {\n        include snippets/fastcgi-php.conf;\n$pvParams        fastcgi_pass unix:$sock;\n$cache    }\n";
        if ($useCustom && $whole === null) {
            $locs = "    include " . self::CUSTOM . "/$d.conf;\n" . $locs;
        }

        if (!empty($vh['suspended'])) {
            $redir = '';
            $locs = "    location / { default_type text/plain; return 503 'Temporarily unavailable: the hosting resource quota of this account is exceeded.'; }\n";
        }

        $body = <<<NGX
    root $root;
    # the web server (www-data) is in every account's group: never follow a symlink whose target has another owner
    disable_symlinks if_not_owner from=\$document_root;
    index index.php index.html index.htm;
    $access
    error_log $logDir/$d.error.log warn;
    client_max_body_size {$t['client_max_body_size']}m;
    $cacheHdr$waf

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
        if ($moved) {
            // old names after a rename: everything (except Let's Encrypt checks) moves permanently to the domain
            $to = ($ssl ? 'https' : 'http') . "://$d";
            $hosts = implode(' ', $moved);
            $redirect = "    server_name $hosts;\n"
                . "    location ^~ /.well-known/acme-challenge/ { root /var/www/havoc-acme; default_type text/plain; try_files \$uri =404; }\n"
                . "    location / { return 301 $to\$request_uri; }\n";
            $out .= "server {\n    listen 80;\n    listen [::]:80;\n$redirect}\n";
            if ($ssl) {
                $out .= "server {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    ssl_certificate {$vh['ssl']['cert']};\n    ssl_certificate_key {$vh['ssl']['key']};\n"
                    . "    ssl_protocols TLSv1.2 TLSv1.3;\n$redirect}\n";
            }
        }
        return $out;
    }
}
