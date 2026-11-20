<?php
declare(strict_types=1);

namespace Havoc\Core;

/**
 * Panel session.
 * - Without "remember me": valid until 24 hours after the last request. An open panel tab sends a heartbeat every few
 *   minutes (app.js), so the session does not end while a tab is open; the cookie carries the same expiry and is
 *   renewed with the session, so the browser deletes it 24 hours after the last tab was closed.
 * - With "remember me": valid for 30 days from the login, whatever happens in between.
 * Expired session files are removed by gc() (hourly, bin/havoc backup:due).
 */
final class Session
{
    public const IDLE = 86400;                 // 24 h after the last request
    public const REMEMBER = 30 * 86400;        // 30 days after the login
    private const RENEW_EVERY = 300;           // the cookie is sent again at most every 5 minutes

    private static bool $https = false;

    public static function start(Request $req): void
    {
        $path = Config::get('session.path');
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        self::$https = $req->isHttps();
        session_name('havoc_sid');
        session_save_path($path);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.gc_maxlifetime', (string)self::REMEMBER);   // PHP's own GC must never remove a remembered session
        // the cookie PHP itself sends (new session, regenerate): a browser-session cookie with the same flags
        session_set_cookie_params(['lifetime' => 0] + array_diff_key(self::cookie(0), ['expires' => 0]));
        session_start();
        $now = time();
        $expired = !empty($_SESSION['remember'])
            ? $now > (int)($_SESSION['remember_until'] ?? 0)
            : isset($_SESSION['last']) && $now - (int)$_SESSION['last'] > self::IDLE;
        if ($expired) {
            self::destroy();
            session_start();
        }
        $_SESSION['last'] = $now;
        if (!empty($_SESSION['uid']) && $now - (int)($_SESSION['cookie_at'] ?? 0) >= self::RENEW_EVERY) {
            self::sendCookie();
        }
    }

    /** After a login: remember for 30 days or not. */
    public static function remember(bool $on): void
    {
        $_SESSION['remember'] = $on;
        $_SESSION['remember_until'] = $on ? time() + self::REMEMBER : 0;
        self::sendCookie();
    }

    /** The session cookie with the session's expiry (24 h from now, or the end of the 30 days). */
    private static function sendCookie(): void
    {
        $exp = !empty($_SESSION['remember']) ? (int)$_SESSION['remember_until'] : time() + self::IDLE;
        $_SESSION['cookie_at'] = time();
        if (!headers_sent()) {
            setcookie(session_name(), (string)session_id(), self::cookie($exp));
        }
    }

    private static function cookie(int $expires): array
    {
        return ['expires' => $expires, 'path' => '/', 'secure' => self::$https, 'httponly' => true, 'samesite' => 'Strict'];
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        unset($_SESSION['cookie_at']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (!headers_sent() && isset($_COOKIE['havoc_sid'])) {
            setcookie('havoc_sid', '', self::cookie(1));         // delete it in the browser too
        }
    }

    /**
     * Removes session files whose session has ended: untouched for 24 hours (not remembered) or 30 days (remembered;
     * the exact end is checked on the next request). Returns the number of files removed.
     */
    public static function gc(): int
    {
        $path = (string)Config::get('session.path');
        $n = 0;
        $now = time();
        foreach (glob($path . '/sess_*') ?: [] as $f) {
            $age = $now - (int)@filemtime($f);
            if ($age <= self::IDLE) {
                continue;
            }
            $remembered = str_contains((string)@file_get_contents($f, false, null, 0, 65536), 'remember|b:1;');
            if (!$remembered || $age > self::REMEMBER) {
                @unlink($f) && $n++;
            }
        }
        return $n;
    }

    public static function flash(string $type, string $msg): void
    {
        $_SESSION['flash'][] = [$type, $msg];
    }

    public static function pullFlash(): array
    {
        $f = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $f;
    }
}
