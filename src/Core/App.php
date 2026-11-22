<?php
declare(strict_types=1);

namespace Havoc\Core;

final class App
{
    public static function run(): void
    {
        self::trustPanelProxy();
        $req = new Request();
        if (!empty($_SERVER['HAVOC_PREVIEW'])) {
            \Havoc\Services\PreviewService::handle((string)$_SERVER['HAVOC_PREVIEW']);   // site preview port (nginx)
            return;
        }
        if (!empty($_SERVER['HAVOC_AUTH_CHECK'])) {
            self::authCheck($req);                            // nginx auth_request subrequest (phpMyAdmin gate)
            return;
        }
        try {
            Config::load();
            if ($req->path === '/oauth/authorize' && $req->method === 'GET' && empty($_COOKIE['havoc_sid']) && !isset($_GET['hop'])) {
                // arriving from claude.ai: the SameSite=Strict session cookie is not sent on a cross-site navigation, so
                // hop once through a same-site reload instead of starting (and overwriting) a new session
                self::hop()->send();
                return;
            }
            if (!$req->isMachine()) {
                Session::start($req);
            }
            Security::headers($req);
            I18n::boot(Auth::user());
            $router = new Router();
            (require HAVOC_ROOT . '/config/routes.php')($router);
            $res = $router->dispatch($req);
        } catch (HttpException $e) {
            $res = self::error($req, $e->status, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[havoc] ' . $e);
            $debug = (bool)Config::get('app.debug', false);
            $res = self::error($req, 500, $debug ? $e->getMessage() : 'Internal error');
        }
        $res->send();
    }

    /**
     * Requests forwarded by the sites' Nginx for https://<panel host>/ (port 443): take the client address and the
     * public port from the forwarding headers, but only from the loopback address and with the shared secret
     * (see ctl/lib/PanelHost.php), so nobody else can fake them.
     */
    private static function trustPanelProxy(): void
    {
        $sent = (string)($_SERVER['HTTP_X_HAVOC_PROXY'] ?? '');
        unset($_SERVER['HTTP_X_HAVOC_PROXY']);
        if ($sent === '' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            return;
        }
        $secret = trim((string)@file_get_contents('/etc/havoc/proxy-secret'));
        if (strlen($secret) !== 64 || !hash_equals($secret, $sent)) {
            return;
        }
        $ip = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $_SERVER['REMOTE_ADDR'] = $ip;
        }
        $port = (string)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '');
        if (preg_match('/^\d{1,5}$/D', $port)) {
            $_SERVER['SERVER_PORT'] = $port;
        }
    }

    private static function hop(): Response
    {
        $q = $_GET + ['hop' => '1'];
        $url = '/oauth/authorize?' . http_build_query($q);
        $u = htmlspecialchars($url, ENT_QUOTES);
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        return new Response("<!doctype html><meta charset=utf-8><meta http-equiv=\"refresh\" content=\"0;url=$u\"><title>Havoc WEB Panel</title><p><a href=\"$u\">Continue</a></p>\n");
    }

    private static function error(Request $req, int $code, string $msg): Response
    {
        if ($req->wantsJson()) {
            return Response::json(['error' => $msg], $code);
        }
        try {
            $r = view('error', ['code' => $code, 'message' => $msg]);
        } catch (\Throwable) {
            $r = new Response("Error $code");
        }
        $r->status = $code;
        return $r;
    }

    /** 204 when the request carries a valid panel session, 401 otherwise. No body, no other side effects. */
    private static function authCheck(Request $req): void
    {
        try {
            Config::load();
            if ($req->path === '/oauth/authorize' && $req->method === 'GET' && empty($_COOKIE['havoc_sid']) && !isset($_GET['hop'])) {
                // arriving from claude.ai: the SameSite=Strict session cookie is not sent on a cross-site navigation, so
                // hop once through a same-site reload instead of starting (and overwriting) a new session
                self::hop()->send();
                return;
            }
            if (!$req->isMachine()) {
                Session::start($req);
            }
            http_response_code(Auth::user() ? 204 : 401);
        } catch (\Throwable $e) {
            error_log('[havoc] auth check: ' . $e->getMessage());
            http_response_code(401);
        }
        header('Cache-Control: no-store');
    }
}
