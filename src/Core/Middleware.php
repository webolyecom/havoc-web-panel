<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Middleware
{
    public static function run(string $name, Request $req): ?Response
    {
        switch ($name) {
            case 'guest':
                return Auth::user() ? Response::redirect('/') : null;
            case 'auth':
                return Auth::user() ? null : ($req->wantsJson()
                    ? Response::json(['error' => 'unauthenticated'], 401) : Response::redirect('/login'));
            case 'reseller':
                return self::role($req, ['admin', 'reseller']);
            case 'admin':
                return self::role($req, ['admin']);
            case 'api':
                return Auth::apiUser($req) ? null : Response::json(['error' => 'invalid_token'], 401);
            case 'apiadmin':
                return Auth::apiUser($req) && Auth::user()['role'] === 'admin' ? null : Response::json(['error' => 'forbidden'], 403);
        }
        throw new \LogicException("Unknown middleware $name");
    }

    private static function role(Request $req, array $roles): ?Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::redirect('/login');
        }
        if (!in_array($u['role'], $roles, true)) {
            throw new HttpException(403);
        }
        return null;
    }
}
