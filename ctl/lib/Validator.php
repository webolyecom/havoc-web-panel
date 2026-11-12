<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/** Strict whitelists. Everything that reaches a file path, config file or command goes through here. */
final class Validator
{
    public const PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4'];
    public const SERVICES = [
        'nginx', 'lshttpd', 'lsws', 'mariadb', 'redis-server', 'meilisearch', 'proftpd', 'pure-ftpd',
        'bind9', 'named', 'cron', 'fail2ban', 'ufw', 'csf', 'lfd', 'havoc-panel-nginx', 'havoc-panel-fpm',
    ];
    public const PROTECTED_SERVICES = ['havoc-panel-nginx', 'havoc-panel-fpm'];   // restart only, never stop

    public static function domain(mixed $d): string
    {
        $d = strtolower(trim((string)$d));
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $d)) {
            throw new \InvalidArgumentException('Invalid domain name');
        }
        return $d;
    }

    public static function account(mixed $a): string
    {
        if (!is_string($a) || !preg_match('/^hv[0-9]{1,9}$/', $a)) {
            throw new \InvalidArgumentException('Invalid account');
        }
        return $a;
    }

    public static function id(mixed $i): int
    {
        if (!is_int($i) && !(is_string($i) && ctype_digit($i))) {
            throw new \InvalidArgumentException('Invalid id');
        }
        return (int)$i;
    }

    public static function php(mixed $v): string
    {
        if (!in_array($v, self::PHP_VERSIONS, true)) {
            throw new \InvalidArgumentException('Unsupported PHP version');
        }
        return $v;
    }

    public static function ext(mixed $e): string
    {
        if (!is_string($e) || !preg_match('/^[a-z0-9_]{2,32}$/', $e)) {
            throw new \InvalidArgumentException('Invalid extension');
        }
        return $e;
    }

    public static function service(mixed $s): string
    {
        if (!in_array($s, self::SERVICES, true)) {
            throw new \InvalidArgumentException('Service not allowed');
        }
        return $s;
    }

    public static function relPath(mixed $p): string
    {
        $p = trim((string)$p, '/');
        if ($p === '' || !preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $p) || str_contains($p, '..')) {
            throw new \InvalidArgumentException('Invalid path');
        }
        return $p;
    }

    public static function cronSchedule(mixed $v): string
    {
        return CronExpr::parse((string)$v)->expr;             // full range checks; throws with a readable reason
    }

    public static function cronCommand(mixed $v): string
    {
        $v = trim((string)$v);
        if ($v === '' || strlen($v) > 1000 || preg_match('/[\r\n\0]/', $v)) {
            throw new \InvalidArgumentException('Invalid command (single line, max 1000 chars)');
        }
        return $v;
    }

    public static function redirectSource(mixed $v): string
    {
        $v = (string)$v;
        if (!preg_match('#^/([A-Za-z0-9._~\-/%]*\*?)$#', $v) || str_starts_with($v, '/.well-known') || strlen($v) > 200
            || ($v !== '/' && str_contains($v, '*') && !str_ends_with($v, '/*'))) {
            throw new \InvalidArgumentException('Invalid source path (use /path, /dir/* or /)');
        }
        return $v;
    }

    public static function redirectTarget(mixed $v): string
    {
        $v = (string)$v;
        if (!preg_match('#^(https?://[A-Za-z0-9._~:/?\#\[\]@!&\'()*+,;=%\-]+|/[A-Za-z0-9._~:/?\#@!&()*+,;=%\-]*)$#', $v) || strlen($v) > 500) {
            throw new \InvalidArgumentException('Invalid target URL');
        }
        return $v;
    }

    public static function redirectCode(mixed $v): int
    {
        if (!in_array((int)$v, [301, 302, 307, 308], true)) {
            throw new \InvalidArgumentException('Invalid redirect code');
        }
        return (int)$v;
    }
}
