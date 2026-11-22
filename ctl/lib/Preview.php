<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Live preview of the sites on this server, also before their DNS points here:
 *   https://<panel>:<panel port>/preview/<domain>/<path>  →  https://<panel>:<preview port>/<path>
 *
 * - The panel port only forwards /preview/… to the preview port (panel port + 1, default 2088). The site never runs on the
 *   panel's own origin, so its JavaScript cannot read panel pages.
 * - On the preview port, /preview/<domain>/ checks the panel login and the right to that site (panel PHP), remembers the
 *   site in the cookie havoc_pv and continues at /<path>. Every other request is checked the same way (nginx auth_request,
 *   answers cached for 20 s) and proxied to this server's own web server with Host: <domain>, so the site runs with its
 *   own paths. Absolute addresses of the domain in pages, CSS, JS and JSON and in redirects are rewritten to the preview
 *   address; the site's cookies are set for the panel host; the panel's own cookies are never passed to the site.
 * - Requests that send data (POST, PUT, PATCH, DELETE) go through the panel's relay (PreviewService::relay), which turns
 *   the preview address in the body back into https://<domain>: forms and editors send back what the page showed.
 * - HTTPS: sites with a certificate are reached over HTTPS (SNI = domain); OpenLiteSpeed / LiteSpeed answer every site on
 *   443 anyway. A site without a certificate on Nginx is reached over HTTP with a secret header, and its Nginx then tells
 *   PHP that the request is HTTPS (map in conf.d/havoc-00-preview.conf), so WordPress & co. do not redirect in a loop.
 */
final class Preview
{
    public const SECRET = '/etc/havoc/preview-secret';
    public const PORT_FILE = '/etc/havoc/preview-port';
    private const SERVER = '/etc/havoc/nginx/servers.d/preview.conf';
    private const FORWARD = '/etc/havoc/nginx/conf.d/preview.conf';
    private const PANEL = '/etc/havoc/nginx/nginx.conf';

    public static function secret(): string
    {
        $s = is_file(self::SECRET) ? trim((string)file_get_contents(self::SECRET)) : '';
        if (!preg_match('/^[a-f0-9]{48}$/', $s)) {
            $s = bin2hex(random_bytes(24));
            Sys::write(self::SECRET, $s . "\n", 0600);
        }
        return $s;
    }

    public static function port(): int
    {
        $p = (int)trim((string)@file_get_contents(self::PORT_FILE));
        if ($p >= 1024 && $p <= 65535) {
            return $p;
        }
        $panel = PanelHost::panelPort() ?: 2087;
        return $panel + 1;
    }

    /** Writes the preview server, the forward on the panel port and opens the port in the firewall. */
    public static function ensure(): array
    {
        $panel = PanelHost::panelPort();
        if ($panel === 0 || !is_file(self::PANEL)) {
            throw new \RuntimeException('The panel web server configuration was not found');
        }
        $port = self::port();
        if ($port === $panel) {
            $port = $panel + 1;
        }
        $main = (string)file_get_contents(self::PANEL);
        if (!str_contains($main, 'servers.d/*.conf')) {
            $n = preg_replace('/^(\s*server_tokens off;\n)/m', "\$1  include /etc/havoc/nginx/servers.d/*.conf;\n", $main, 1);
            if ($n === null || $n === $main) {
                throw new \RuntimeException('Could not add the preview server to the panel web server configuration');
            }
            Sys::write(self::PANEL, $n, 0644);
        }
        is_dir(dirname(self::SERVER)) || mkdir(dirname(self::SERVER), 0755, true);
        is_dir('/var/lib/havoc-panel-nginx/preview-auth') || mkdir('/var/lib/havoc-panel-nginx/preview-auth', 0700, true);
        @chown('/var/lib/havoc-panel-nginx/preview-auth', 'havoc');
        $old = [self::SERVER => @file_get_contents(self::SERVER), self::FORWARD => @file_get_contents(self::FORWARD), self::PORT_FILE => @file_get_contents(self::PORT_FILE)];
        $http2 = str_contains($main, 'http2 on;');
        Sys::write(self::SERVER, self::server($port, $panel, self::secret(), $http2), 0640);
        Sys::write(self::FORWARD, "# Managed by Havoc WEB Panel - site previews run on their own port (not on the panel's origin)\n"
            . "location ^~ /preview/ { return 302 https://\$host:$port\$request_uri; }\n", 0644);
        Sys::write(self::PORT_FILE, "$port\n", 0644);
        [$c, , $e] = Sys::run(['/usr/sbin/nginx', '-t', '-c', self::PANEL]);
        if ($c !== 0) {
            foreach ($old as $f => $v) {
                $v === false ? @unlink($f) : Sys::write($f, $v, $f === self::SERVER ? 0640 : 0644);
            }
            throw new \RuntimeException('Preview configuration rejected by nginx: ' . mb_substr(trim((string)$e), 0, 300));
        }
        Sys::run(['systemctl', 'reload', 'havoc-panel-nginx']);
        // keep the port reachable when the firewall is on (also a protected port from now on)
        if (is_executable('/usr/sbin/ufw')) {
            [$fc, $fo] = Sys::run(['/usr/sbin/ufw', 'status']);
            if ($fc === 0 && str_contains((string)$fo, 'Status: active')) {
                Sys::run(['/usr/sbin/ufw', 'allow', "$port/tcp", 'comment', 'havoc:preview']);
            }
        }
        return ['port' => $port];
    }

    private static function server(int $port, int $panel, string $secret, bool $http2): string
    {
        $listen = $http2 ? "    listen $port ssl;\n    listen [::]:$port ssl;\n    http2 on;\n" : "    listen $port ssl http2;\n    listen [::]:$port ssl http2;\n";
        $fcgi = "        include /etc/nginx/fastcgi_params;\n        fastcgi_param SCRIPT_FILENAME /opt/havoc/public/index.php;\n        fastcgi_param HTTPS on;\n"
            . "        fastcgi_param HAVOC_PANEL_PORT $panel;\n";
        // absolute addresses of the site → the preview address (pages, CSS, JS, JSON incl. "https:\/\/" and "//")
        $subs = '';
        foreach (['https://www.', 'http://www.', 'https://', 'http://'] as $p) {
            $subs .= "        sub_filter \"$p\$havoc_pv_host\" \"https://\$http_host\";\n";
        }
        foreach (['https:\\\\/\\\\/www.', 'http:\\\\/\\\\/www.', 'https:\\\\/\\\\/', 'http:\\\\/\\\\/'] as $p) {
            $subs .= "        sub_filter \"$p\$havoc_pv_host\" \"https:\\\\/\\\\/\$http_host\";\n";
        }
        foreach (['https%3A%2F%2Fwww.', 'http%3A%2F%2Fwww.', 'https%3A%2F%2F', 'http%3A%2F%2F'] as $p) {   // URL-encoded (redirect_to=…)
            $subs .= "        sub_filter \"$p\$havoc_pv_host\" \"https%3A%2F%2F\$http_host\";\n";
        }
        $subs .= "        sub_filter \"//www.\$havoc_pv_host\" \"//\$http_host\";\n        sub_filter \"//\$havoc_pv_host\" \"//\$http_host\";\n";
        $redir = '';
        foreach (['https://www.', 'http://www.', 'https://', 'http://'] as $p) {
            $redir .= "        proxy_redirect $p\$havoc_pv_host/ /;\n";
        }
        $rw = "        sub_filter_types text/css text/xml text/javascript application/javascript application/x-javascript application/json application/ld+json application/xml application/rss+xml application/manifest+json;\n"
            . "        sub_filter_once off;\n$subs";
        return <<<NGX
# Managed by Havoc WEB Panel - live preview of this server's sites (Preview.php)
fastcgi_cache_path /var/lib/havoc-panel-nginx/preview-auth levels=1 keys_zone=havoc_pvauth:2m max_size=20m inactive=2m;
# the panel's own cookies never reach a site
map \$http_cookie \$havoc_pv_c1 { default \$http_cookie; "~^(?<a>.*?)(?:;\\s*)?\\bhavoc_sid=[^;]*(?<b>.*)\$" "\$a\$b"; }
map \$havoc_pv_c1 \$havoc_pv_cookie { default \$havoc_pv_c1; "~^(?<a>.*?)(?:;\\s*)?\\bhavoc_pv=[^;]*(?<b>.*)\$" "\$a\$b"; }
# the site sees its own address as Origin / Referer (CSRF checks of the site)
map \$http_origin \$havoc_pv_origin { "" ""; default "https://\$havoc_pv_host"; }
map \$http_referer \$havoc_pv_referer { "" ""; "~^https?://[^/]+(?<rest>/.*)?\$" "https://\$havoc_pv_host\$rest"; default \$http_referer; }
server {
$listen    ssl_certificate /etc/havoc/ssl/panel.crt;
    ssl_certificate_key /etc/havoc/ssl/panel.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    server_tokens off;
    client_max_body_size 1024m;
    proxy_read_timeout 600s;
    proxy_send_timeout 600s;
    access_log /var/log/havoc/panel/preview-access.log;
    recursive_error_pages on;   # 418 → relay → 401/403 → login / notice
    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml text/plain text/xml;
    gzip_min_length 1024;

    # entry: /preview/<domain>/<path> (only names with a dot, so a site's own /preview/... pages still work)
    location ~ ^/preview/[a-z0-9-]+(\\.[a-z0-9-]+)+(/|\$) {
$fcgi        fastcgi_param HAVOC_PREVIEW enter;
        fastcgi_pass unix:/run/havoc-panel.sock;
    }
    location = /_havoc_preview_auth {
        internal;
$fcgi        fastcgi_param HAVOC_PREVIEW auth;
        fastcgi_pass_request_body off;
        fastcgi_param CONTENT_LENGTH "";
        fastcgi_cache havoc_pvauth;
        fastcgi_cache_key "\$cookie_havoc_sid|\$cookie_havoc_pv";
        fastcgi_cache_valid 204 401 403 20s;
        fastcgi_ignore_headers Cache-Control Expires Set-Cookie;
        fastcgi_pass unix:/run/havoc-panel.sock;
    }
    location / {
        auth_request /_havoc_preview_auth;
        auth_request_set \$havoc_pv_host \$upstream_http_x_pv_host;
        auth_request_set \$havoc_pv_scheme \$upstream_http_x_pv_scheme;
        error_page 401 = @havoc_pv_login;
        error_page 403 = @havoc_pv_none;
        # requests that send data go through the panel's relay: the preview address in forms / editors → the domain
        error_page 418 = @havoc_pv_relay;
        if (\$request_method !~ ^(GET|HEAD|OPTIONS)\$) { return 418; }
        proxy_pass \$havoc_pv_scheme://127.0.0.1;
        proxy_http_version 1.1;
        proxy_set_header Host \$havoc_pv_host;
        proxy_set_header Cookie \$havoc_pv_cookie;
        proxy_set_header X-Havoc-Preview "$secret";
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Accept-Encoding "";
        proxy_set_header Origin \$havoc_pv_origin;
        proxy_set_header Referer \$havoc_pv_referer;
        proxy_ssl_server_name on;
        proxy_ssl_name \$havoc_pv_host;
        proxy_ssl_verify off;
        proxy_buffer_size 32k;
        proxy_buffers 16 32k;
$redir        proxy_cookie_domain ~.+ \$host;
        proxy_hide_header Strict-Transport-Security;
$rw    }
    location @havoc_pv_relay {
        auth_request /_havoc_preview_auth;
        auth_request_set \$havoc_pv_host \$upstream_http_x_pv_host;
        auth_request_set \$havoc_pv_scheme \$upstream_http_x_pv_scheme;
        error_page 401 = @havoc_pv_login;
        error_page 403 = @havoc_pv_none;
$fcgi        fastcgi_param HAVOC_PREVIEW relay;
        fastcgi_param HAVOC_PV_HOST \$havoc_pv_host;
        fastcgi_param HAVOC_PV_SCHEME \$havoc_pv_scheme;
        fastcgi_param HAVOC_PV_COOKIE \$havoc_pv_cookie;
        fastcgi_param HAVOC_PV_SECRET "$secret";
        # never PHP_VALUE here: FPM keeps such values for the next requests of the worker (the panel's own forms)
        fastcgi_read_timeout 660s;
        fastcgi_pass unix:/run/havoc-panel.sock;
$rw    }
    location @havoc_pv_login { return 302 https://\$host:$panel/login; }
    location @havoc_pv_none {
        default_type text/html;
        return 403 '<!doctype html><meta charset=utf-8><title>Preview</title><body style="font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;color:#334155"><div style="text-align:center;max-width:36rem"><h1 style="font-size:20px">No site selected for preview</h1><p>Open a site with <code>https://\$host:$panel/preview/&lt;domain&gt;/</code>, or from its page in the panel. Only sites you manage can be previewed.</p></div>';
    }
}

NGX;
    }
}
