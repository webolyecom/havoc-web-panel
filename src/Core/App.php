<?php
declare(strict_types=1);

namespace Havoc\Core;

final class App
{
    public static function run(): void
    {
        $req = new Request();
        if (!empty($_SERVER['HAVOC_AUTH_CHECK'])) {
            self::authCheck($req);                            // nginx auth_request subrequest (phpMyAdmin gate)
            return;
        }
        try {
            Config::load();
            Session::start($req);
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
            Session::start($req);
            http_response_code(Auth::user() ? 204 : 401);
        } catch (\Throwable $e) {
            error_log('[havoc] auth check: ' . $e->getMessage());
            http_response_code(401);
        }
        header('Cache-Control: no-store');
    }
}
