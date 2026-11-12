<?php
declare(strict_types=1);

namespace Havoc\Core;

final class I18n
{
    public const SUPPORTED = ['en' => 'English', 'tr' => 'Türkçe'];
    private static string $locale = 'en';
    private static array $msgs = [];
    private static array $fallback = [];

    public static function boot(?array $user): void
    {
        $default = Config::get('app.locale', 'en');
        $cand = $user['locale'] ?? $_COOKIE['havoc_lang'] ?? $default;
        self::$locale = isset(self::SUPPORTED[$cand]) ? $cand : 'en';
        self::$fallback = require HAVOC_ROOT . '/lang/en.php';
        self::$msgs = self::$locale === 'en' ? self::$fallback : require HAVOC_ROOT . '/lang/' . self::$locale . '.php';
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function t(string $key, array $replace = []): string
    {
        $s = self::$msgs[$key] ?? self::$fallback[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $s = str_replace(':' . $k, (string)$v, $s);
        }
        return $s;
    }
}
