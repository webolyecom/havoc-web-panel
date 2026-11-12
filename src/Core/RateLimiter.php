<?php
declare(strict_types=1);

namespace Havoc\Core;

/** DB backed brute-force protection for login. */
final class RateLimiter
{
    public static function locked(string $ip, string $username): bool
    {
        $max  = (int)Config::get('security.max_login_attempts', 5);
        $from = time() - 60 * (int)Config::get('security.lockout_minutes', 15);
        DB::q('DELETE FROM login_attempts WHERE ts < ?', [$from]);
        $byUser = (int)DB::q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND ts >= ?', [$ip, strtolower($username), $from])->fetchColumn();
        $byIp   = (int)DB::q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ts >= ?', [$ip, $from])->fetchColumn();
        return $byUser >= $max || $byIp >= $max * 4;
    }

    public static function hit(string $ip, string $username): void
    {
        DB::insert('login_attempts', ['ip' => $ip, 'username' => strtolower($username), 'ts' => time()]);
    }

    public static function clear(string $ip, string $username): void
    {
        DB::q('DELETE FROM login_attempts WHERE ip = ? AND username = ?', [$ip, strtolower($username)]);
    }
}
