<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Settings
{
    public static function get(string $k, string $default = ''): string
    {
        $v = DB::q('SELECT v FROM settings WHERE k = ?', [$k])->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    public static function set(string $k, string $v): void
    {
        DB::q('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
    }
}
