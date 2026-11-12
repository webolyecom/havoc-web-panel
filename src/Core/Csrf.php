<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function verify(Request $req): void
    {
        $sent = $_POST['_csrf'] ?? $req->header('X-CSRF-Token') ?? '';
        if (!is_string($sent) || !hash_equals(self::token(), $sent)) {
            throw new HttpException(419, 'CSRF token mismatch');
        }
    }
}
