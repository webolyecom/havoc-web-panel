<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['uid'] ?? null;
            if ($id) {
                $u = DB::one('SELECT * FROM users WHERE id = ? AND status = ?', [$id, 'active']);
                self::$user = $u;
                if (!$u) {
                    unset($_SESSION['uid']);
                }
            }
        }
        return self::$user;
    }

    public static function setUser(?array $u): void
    {
        self::$user = $u;
        self::$loaded = true;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    /** @return array{0:string,1:?array} status: ok|2fa|invalid|locked */
    public static function attempt(string $username, string $password, string $ip): array
    {
        if (RateLimiter::locked($ip, $username)) {
            return ['locked', null];
        }
        $u = DB::one('SELECT * FROM users WHERE username = ?', [$username]);
        // constant-ish time: always verify against some hash
        $hash = $u['password'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($password, $hash);
        if (!$u || !$ok || $u['status'] !== 'active') {
            RateLimiter::hit($ip, $username);
            Audit::log('auth.failed', $username, [], ['id' => null, 'username' => $username]);
            return ['invalid', null];
        }
        if (password_needs_rehash($u['password'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            DB::update('users', (int)$u['id'], ['password' => self::hash($password)]);
        }
        if ($u['totp_enabled']) {
            $_SESSION['pending_2fa'] = (int)$u['id'];
            $_SESSION['pending_2fa_at'] = time();
            return ['2fa', $u];
        }
        self::login($u, $ip);
        return ['ok', $u];
    }

    public static function login(array $u, string $ip): void
    {
        Session::regenerate();
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_at']);
        $_SESSION['uid'] = (int)$u['id'];
        RateLimiter::clear($ip, $u['username']);
        self::setUser($u);
        Audit::log('auth.login', $u['username'], [], $u);
    }

    public static function logout(): void
    {
        Audit::log('auth.logout');
        self::setUser(null);
        Session::destroy();
    }

    /** Bearer token auth for /api/v1. Tokens are stored as SHA-256 hashes. */
    public static function apiUser(Request $req): ?array
    {
        $tok = $req->bearer();
        if (!$tok || !preg_match('/^hvc_[a-f0-9]{48}$/', $tok)) {
            return null;
        }
        $row = DB::one('SELECT u.*, t.id AS token_id FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND u.status = ?', [hash('sha256', $tok), 'active']);
        if (!$row) {
            return null;
        }
        DB::q('UPDATE api_tokens SET last_used = CURRENT_TIMESTAMP WHERE id = ?', [$row['token_id']]);
        self::setUser($row);
        return $row;
    }
}
