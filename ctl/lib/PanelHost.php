<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Serves the panel on https://<panel host>/ (port 443) in addition to its own port, through the sites' Nginx: some
 * clients, including Claude's connector service, only reach servers on the standard HTTPS port.
 *
 * The site Nginx forwards to the panel's own Nginx on 127.0.0.1:<panel port> and adds the client IP and port 443.
 * The panel trusts those headers only for requests from the loopback address that also carry a random secret,
 * which only root, Nginx (config 0640) and the panel user (0640 root:havoc) can read, so hosting accounts that can
 * open connections to 127.0.0.1 cannot fake their address.
 */
final class PanelHost
{
    public const HOST_FILE = '/etc/havoc/panel-host';
    public const SECRET    = '/etc/havoc/proxy-secret';
    private const CONF     = '/etc/nginx/conf.d/havoc-001-panel.conf';
    private const PANEL    = '/etc/havoc/nginx/nginx.conf';
    private const LIVE     = '/etc/letsencrypt/live/havoc-panel';

    public static function host(): string
    {
        return trim((string)@file_get_contents(self::HOST_FILE));
    }

    public static function panelPort(): int
    {
        return preg_match('/listen\s+(\d+)\s+ssl/', (string)@file_get_contents(self::PANEL), $m) ? (int)$m[1] : 0;
    }

    public static function active(): bool
    {
        return is_file(self::CONF);
    }

    private static function secret(): string
    {
        $s = trim((string)@file_get_contents(self::SECRET));
        if (!preg_match('/^[a-f0-9]{64}$/D', $s)) {
            $s = bin2hex(random_bytes(32));
            Sys::write(self::SECRET, $s . "\n", 0640, 'root', 'havoc');
        }
        return $s;
    }

    /** (Re)writes the forwarding server; removes it when it cannot work (no host, no certificate, LiteSpeed active). */
    public static function apply(): array
    {
        $host = self::host();
        $port = self::panelPort();
        if ($host === '' || !$port || !is_file(self::LIVE . '/fullchain.pem') || WebServerFactory::active() !== 'nginx' || $port === 443) {
            self::remove();
            return ['active' => false];
        }
        Validator::domain($host);
        $secret = self::secret();
        $http2 = version_compare((new NginxDriver())->version() ?: '0', '1.25.1', '>=');
        $listen = $http2 ? "listen 443 ssl;\n    listen [::]:443 ssl;\n    http2 on;" : "listen 443 ssl http2;\n    listen [::]:443 ssl http2;";
        $live = self::LIVE;
        $conf = <<<NGX
# Managed by Havoc WEB Panel - the panel on https://$host/ (forwarded to its own port $port)
server {
    $listen
    server_name $host;
    ssl_certificate $live/fullchain.pem;
    ssl_certificate_key $live/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    access_log off;
    client_max_body_size 64m;
    location / {
        proxy_pass https://127.0.0.1:$port;
        proxy_ssl_server_name on;
        proxy_ssl_name $host;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-Port 443;
        proxy_set_header X-Havoc-Proxy $secret;
        proxy_set_header Connection "";
        proxy_read_timeout 660s;
        proxy_send_timeout 660s;
        proxy_request_buffering off;
        proxy_buffering off;
    }
}
server {
    listen 80;
    listen [::]:80;
    server_name $host;
    location ^~ /.well-known/acme-challenge/ { root /var/www/havoc-acme; default_type text/plain; try_files \$uri =404; }
    location / { return 301 https://\$host\$request_uri; }
}

NGX;
        $old = is_file(self::CONF) ? (string)file_get_contents(self::CONF) : null;
        Sys::write(self::CONF, $conf, 0640);                         // holds the secret: not readable by hosting accounts
        [$c, , $e] = Sys::run(['/usr/sbin/nginx', '-t']);
        if ($c !== 0) {
            $old === null ? @unlink(self::CONF) : Sys::write(self::CONF, $old, 0640);
            throw new \RuntimeException('Nginx rejected the panel forwarding: ' . trim($e));
        }
        Sys::run(['systemctl', 'reload', 'nginx']);
        return ['active' => true, 'url' => "https://$host"];
    }

    public static function remove(): void
    {
        if (is_file(self::CONF)) {
            @unlink(self::CONF);
            Sys::run(['systemctl', 'reload', 'nginx']);
        }
    }
}
