<?php
declare(strict_types=1);

namespace Havoc\Core;

/** Authenticated encryption (libsodium secretbox) for stored secrets such as remote backup credentials. */
final class Crypt
{
    private static function key(): string
    {
        return sodium_crypto_generichash((string)Config::get('app.key'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($n . sodium_crypto_secretbox($plain, $n, self::key()));
    }

    public static function decrypt(string $b64): ?string
    {
        $raw = base64_decode($b64, true);
        $nl = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($raw === false || strlen($raw) < $nl + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $p = sodium_crypto_secretbox_open(substr($raw, $nl), substr($raw, 0, $nl), self::key());
        return $p === false ? null : $p;
    }
}
