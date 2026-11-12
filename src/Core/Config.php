<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Config
{
    private static ?array $data = null;

    public static function load(): void
    {
        $candidates = [getenv('HAVOC_CONFIG') ?: '', '/etc/havoc/config.php', HAVOC_ROOT . '/config/config.php.dist'];
        foreach ($candidates as $f) {
            if ($f !== '' && is_file($f) && is_readable($f)) {
                self::$data = require $f;
                // the version belongs to the code, not to the config file written at install time
                $v = @file_get_contents(HAVOC_ROOT . '/VERSION');
                if ($v !== false && trim($v) !== '') {
                    self::$data['app']['version'] = trim($v);
                }
                return;
            }
        }
        throw new \RuntimeException('Havoc configuration not found.');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            self::load();
        }
        $v = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }
}
