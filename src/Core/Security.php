<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Security
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    public static function headers(Request $req): void
    {
        $n = self::nonce();
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$n'; style-src 'self' 'nonce-$n'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cache-Control: no-store');
        if ($req->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
