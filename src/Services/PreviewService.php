<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\Config;
use Havoc\Core\DB;
use Havoc\Core\Scope;
use Havoc\Core\Session;

/**
 * Live site preview (see ctl/lib/Preview.php for the web server side), called by the panel's nginx on the preview port:
 *  - enter(): /preview/<domain>/<path> — logged-in panel user with access to that site → cookie havoc_pv, 302 to /<path>;
 *  - auth():  auth_request for every other request — 204 with X-Pv-Host / X-Pv-Scheme, 401 (log in) or 403 (no site);
 *  - relay(): requests that send data (POST, PUT, PATCH, DELETE). Pages show the preview address instead of the domain;
 *    a form or editor sends that address back, so it is turned into https://<domain> again here before the request goes
 *    to the site — otherwise saving a setting or a post would store the preview address in the site.
 * The panel session is only read here (never written or locked), so parallel requests of a page are not serialized.
 */
final class PreviewService
{
    public const COOKIE = 'havoc_pv';

    public static function handle(string $what): void
    {
        header('Cache-Control: no-store');
        try {
            Config::load();
            match ($what) { 'enter' => self::enter(), 'relay' => self::relay(), default => self::auth() };
        } catch (\Throwable $e) {
            error_log('[havoc] preview: ' . $e->getMessage());
            http_response_code(500);
        }
    }

    private static function auth(): void
    {
        $u = Session::peekUser();
        if (!$u) {
            http_response_code(401);
            return;
        }
        $d = self::site($u, (string)($_COOKIE[self::COOKIE] ?? ''));
        if (!$d) {
            http_response_code(403);
            return;
        }
        header('X-Pv-Host: ' . $d['name']);
        header('X-Pv-Scheme: ' . self::scheme($d));
        http_response_code(204);
    }

    private static function enter(): void
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (!preg_match('#^/preview/([a-z0-9.-]+)(/[^?]*)?(\?.*)?$#i', $uri, $m)) {
            self::page(404, 'Not found');
            return;
        }
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $hostOnly = preg_replace('/:\d+$/', '', $host);
        $panel = (int)($_SERVER['HAVOC_PANEL_PORT'] ?? 0);
        $u = Session::peekUser();
        if (!$u) {
            header('Location: https://' . $hostOnly . ($panel ? ":$panel" : '') . '/login', true, 302);
            return;
        }
        $d = self::site($u, strtolower($m[1]));
        if (!$d) {
            self::page(404, 'This site is not on this server, or you do not manage it.');
            return;
        }
        setcookie(self::COOKIE, $d['name'], ['expires' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
        $path = ($m[2] ?? '') !== '' ? $m[2] : '/';
        header('Location: ' . $path . ($m[3] ?? ''), true, 302);
    }

    private const REWRITE_MAX = 20 * 1048576;   // bodies up to 20 MB are checked for the preview address (bigger: uploads)
    private const HOP = ['connection', 'keep-alive', 'transfer-encoding', 'te', 'trailer', 'upgrade', 'proxy-authorization', 'proxy-connection', 'content-length'];

    /** The preview address in a request body or header → the site's own address. */
    public static function restore(string $s, string $previewHost, string $domain): string
    {
        $h = preg_quote($previewHost, '#');
        $he = implode('(?::|%3A)', array_map(static fn($x) => preg_quote($x, '#'), explode(':', $previewHost)));   // port separator may be URL-encoded
        return (string)preg_replace(
            ["#https?://$h(?![a-z0-9.-])#i", "#https?:\\\\/\\\\/$h(?![a-z0-9.-])#i", "#https?%3A%2F%2F$he(?![a-z0-9.-])#i", "#(?<![a-z0-9.-])//$h(?![a-z0-9.-])#i"],
            ["https://$domain", "https:\\/\\/$domain", "https%3A%2F%2F$domain", "//$domain"], $s);
    }

    /**
     * Forwards a request with a body to the site (called from nginx with the checked host in HAVOC_PV_HOST), with the
     * preview address in the body turned back into the domain. The answer goes back through nginx, which rewrites it
     * the same way as other answers; Location and cookie domains are fixed here.
     */
    private static function relay(): void
    {
        $host = (string)($_SERVER['HAVOC_PV_HOST'] ?? '');
        $scheme = ($_SERVER['HAVOC_PV_SCHEME'] ?? '') === 'http' ? 'http' : 'https';
        $preview = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[a-z0-9.-]{3,253}$/', $host) || $preview === '') {
            http_response_code(403);
            return;
        }
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'POST');
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (!str_starts_with($k, 'HTTP_') || in_array($k, ['HTTP_HOST', 'HTTP_COOKIE', 'HTTP_ACCEPT_ENCODING', 'HTTP_X_HAVOC_PREVIEW'], true)) {
                continue;
            }
            $name = str_replace('_', '-', substr($k, 5));
            if (in_array(strtolower($name), self::HOP, true)) {
                continue;
            }
            $headers[] = $name . ': ' . self::restore((string)$v, $preview, $host);
        }
        foreach (['CONTENT_TYPE' => 'Content-Type'] as $k => $n) {
            if (!empty($_SERVER[$k])) {
                $headers[] = "$n: " . $_SERVER[$k];
            }
        }
        $cookie = (string)($_SERVER['HAVOC_PV_COOKIE'] ?? '');
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        $headers[] = 'X-Havoc-Preview: ' . (string)($_SERVER['HAVOC_PV_SECRET'] ?? '');
        $headers[] = 'X-Forwarded-Proto: https';
        $headers[] = 'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '');
        $headers[] = 'Expect:';
        $ch = curl_init("$scheme://$host" . $uri);
        $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RESOLVE => ["$host:443:127.0.0.1", "$host:80:127.0.0.1"],
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 600,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => false, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
        @set_time_limit(660);
        @ini_set('memory_limit', '256M');
        $in = fopen('php://input', 'rb');
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0) {
            // PHP has parsed it (php://input is empty for multipart): rebuild the same fields and files
            $fields = [];
            self::flatten($_POST, '', $fields, $preview, $host);
            self::files($_FILES, $fields);
            $opt[CURLOPT_POSTFIELDS] = $fields;
            $headers = array_values(array_filter($headers, static fn($h) => stripos($h, 'Content-Type:') !== 0));   // curl sets the boundary
            $opt[CURLOPT_HTTPHEADER] = $headers;
        } elseif ($len > 0 && $len <= self::REWRITE_MAX) {
            $opt[CURLOPT_POSTFIELDS] = self::restore((string)stream_get_contents($in), $preview, $host);
        } elseif ($len > 0) {
            $opt += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $in, CURLOPT_INFILESIZE => $len];
        } else {
            $opt[CURLOPT_POSTFIELDS] = '';
        }
        $started = false;
        $status = 502;
        $resp = [];
        $opt[CURLOPT_HEADERFUNCTION] = static function ($c, string $line) use (&$status, &$resp): int {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                $resp = [];                                               // a 100 Continue is followed by the real answer
            } elseif (str_contains($line, ':')) {
                $resp[] = rtrim($line, "\r\n");
            }
            return strlen($line);
        };
        $emit = static function () use (&$started, &$status, &$resp, $host): void {
            if ($started) {
                return;
            }
            $started = true;
            http_response_code($status);
            header_remove('Cache-Control');
            $d = preg_quote($host, '#');
            foreach ($resp as $h) {
                [$n, $v] = array_map('trim', explode(':', $h, 2));
                $ln = strtolower($n);
                if (in_array($ln, self::HOP, true) || $ln === 'strict-transport-security') {
                    continue;
                }
                if ($ln === 'location') {
                    $v = (string)preg_replace("#^https?://(www\.)?$d(?=/|$)#i", '', $v) ?: '/';
                } elseif ($ln === 'set-cookie') {
                    $v = (string)preg_replace('/;\s*domain=[^;]*/i', '', $v);   // host-only cookie of the preview address
                }
                header("$n: $v", false);
            }
        };
        $opt[CURLOPT_WRITEFUNCTION] = static function ($c, string $data) use ($emit): int {
            $emit();
            echo $data;
            return strlen($data);
        };
        curl_setopt_array($ch, $opt);
        $ok = curl_exec($ch);
        if ($ok === false && !$started) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'The site did not answer: ' . curl_error($ch);
            return;
        }
        $emit();
    }

    /** $_POST → flat multipart fields ("a[b][0]" => value) with the preview address restored. */
    private static function flatten(array $data, string $prefix, array &$out, string $preview, string $host): void
    {
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '[' . $k . ']';
            is_array($v) ? self::flatten($v, $key, $out, $preview, $host) : $out[$key] = self::restore((string)$v, $preview, $host);
        }
    }

    /** $_FILES (also nested names) → CURLFile fields. */
    private static function files(array $files, array &$out): void
    {
        $walk = static function ($name, $tmp, $type, $orig, $err, string $key) use (&$walk, &$out): void {
            if (is_array($tmp)) {
                foreach ($tmp as $i => $t) {
                    $walk($name[$i] ?? '', $t, $type[$i] ?? '', $orig[$i] ?? '', $err[$i] ?? 4, $key . '[' . $i . ']');
                }
            } elseif ((int)$err === UPLOAD_ERR_OK && is_uploaded_file((string)$tmp)) {
                $out[$key] = new \CURLFile((string)$tmp, (string)$type ?: 'application/octet-stream', (string)$orig);
            }
        };
        foreach ($files as $field => $f) {
            $walk($f['name'], $f['tmp_name'], $f['type'], $f['name'], $f['error'], (string)$field);
        }
    }

    /** The site (main, addon or subdomain; an alias opens its site) the user may manage. */
    private static function site(array $u, string $name): ?array
    {
        if ($name === '' || !preg_match('/^[a-z0-9.-]{3,253}$/', $name)) {
            return null;
        }
        $row = DB::one("SELECT * FROM domains WHERE name = ? AND status = 'active'", [$name]);
        if ($row && $row['type'] === 'alias') {
            $row = DB::one("SELECT * FROM domains WHERE id = ? AND status = 'active'", [$row['parent_id']]);
        }
        return $row ? Scope::domain($u, (int)$row['id']) : null;
    }

    /**
     * How the preview reaches the site on this server: over HTTPS when it has a certificate (and always on LiteSpeed,
     * which answers every site on 443), otherwise over HTTP with the preview header (its Nginx then reports HTTPS to PHP).
     */
    private static function scheme(array $d): string
    {
        $ws = trim((string)@file_get_contents('/etc/havoc/webserver')) ?: 'nginx';
        return $ws !== 'nginx' || ($d['ssl_mode'] ?? 'none') !== 'none' ? 'https' : 'http';
    }

    public static function url(array $d): string
    {
        $host = trim((string)@file_get_contents('/etc/havoc/panel-host')) ?: (string)($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host);
        $port = (int)trim((string)@file_get_contents('/etc/havoc/preview-port')) ?: ((int)($_SERVER['SERVER_PORT'] ?? 2087) + 1);
        return "https://$host:$port/preview/" . $d['name'] . '/';
    }

    private static function page(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset=utf-8><title>Preview</title><body style="font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;color:#334155"><p>'
            . htmlspecialchars($msg) . '</p>';
    }
}
