<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Session
{
    public static function start(Request $req): void
    {
        $path = Config::get('session.path');
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        session_name('havoc_sid');
        session_save_path($path);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.sid_length', '48');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $req->isHttps(),
            'httponly' => true, 'samesite' => 'Strict',
        ]);
        session_start();
        $life = (int)Config::get('session.lifetime', 3600);
        if (isset($_SESSION['last']) && time() - $_SESSION['last'] > $life) {
            self::destroy();
            session_start();
        }
        $_SESSION['last'] = time();
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
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
