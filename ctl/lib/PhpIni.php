<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Per-domain php.ini settings. Hosting users may set the whitelisted directives below (written as php_value, so scripts can
 * still adjust them with ini_set). Directives that keep accounts apart (open_basedir, disable_functions, session.save_path,
 * extension loading ...) are never accepted from users. Admins may add further lines, which are written as php_admin_value.
 */
final class PhpIni
{
    /** directive => [type, extra] ; types: size, int(min,max), bool, enum(list), tz, charset */
    public const USER = [
        'memory_limit'           => ['size', [16, 4096]],
        'upload_max_filesize'    => ['size', [1, 4096]],
        'post_max_size'          => ['size', [1, 4096]],
        'max_execution_time'     => ['int', [0, 3600]],
        'max_input_time'         => ['int', [-1, 3600]],
        'max_input_vars'         => ['int', [100, 100000]],
        'max_file_uploads'       => ['int', [1, 1000]],
        'default_socket_timeout' => ['int', [1, 3600]],
        'display_errors'         => ['bool', null],
        'log_errors'             => ['bool', null],
        'error_reporting'        => ['enum', ['E_ALL', 'E_ALL & ~E_DEPRECATED & ~E_STRICT', 'E_ALL & ~E_NOTICE', 'E_ALL & ~E_NOTICE & ~E_DEPRECATED', 'E_ERROR | E_WARNING | E_PARSE', '0']],
        'short_open_tag'         => ['bool', null],
        'allow_url_fopen'        => ['bool', null],
        'output_buffering'       => ['enum', ['0', '4096', '8192', 'On', 'Off']],
        'date.timezone'          => ['tz', null],
        'default_charset'        => ['charset', null],
        'session.gc_maxlifetime' => ['int', [60, 2592000]],
        'session.cookie_lifetime' => ['int', [0, 31536000]],
        'session.cookie_secure'  => ['bool', null],
        'session.cookie_httponly' => ['bool', null],
        'session.cookie_samesite' => ['enum', ['Lax', 'Strict', 'None']],
        'mbstring.language'      => ['enum', ['neutral', 'English', 'Japanese', 'uni']],
        'realpath_cache_size'    => ['size', [1, 64]],
        'pcre.backtrack_limit'   => ['int', [1000, 100000000]],
    ];

    /** Never settable, not even by an admin through the extra lines (they would break isolation or the pool itself). */
    private const FORBIDDEN = ['open_basedir', 'disable_functions', 'disable_classes', 'session.save_path', 'upload_tmp_dir', 'sys_temp_dir',
        'extension', 'zend_extension', 'extension_dir', 'auto_prepend_file', 'auto_append_file', 'include_path', 'error_log', 'user_ini.filename',
        'opcache.enable', 'opcache.memory_consumption', 'opcache.preload', 'opcache.preload_user', 'opcache.restrict_api', 'mail.log', 'sendmail_path'];

    public static function normalize(string $key, mixed $value): string
    {
        [$type, $x] = self::USER[$key] ?? throw new \InvalidArgumentException("php.ini setting not allowed: $key");
        $v = trim((string)$value);
        switch ($type) {
            case 'size':
                if (!preg_match('/^(\d{1,5})\s*([MG]?)$/i', $v, $m)) {
                    throw new \InvalidArgumentException("$key: use a size like 256M or 1G");
                }
                if ($m[2] === '') {
                    throw new \InvalidArgumentException("$key: use a size like 256M or 1G");
                }
                $mb = (int)$m[1] * (strtoupper($m[2]) === 'G' ? 1024 : 1);
                if ($mb < $x[0] || $mb > $x[1]) {
                    throw new \InvalidArgumentException("$key: allowed {$x[0]}M - {$x[1]}M");
                }
                return $mb % 1024 === 0 && $mb >= 1024 ? ($mb / 1024) . 'G' : $mb . 'M';
            case 'int':
                if (!preg_match('/^-?\d{1,10}$/', $v) || (int)$v < $x[0] || (int)$v > $x[1]) {
                    throw new \InvalidArgumentException("$key: whole number {$x[0]} - {$x[1]}");
                }
                return (string)(int)$v;
            case 'bool':
                $b = filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    throw new \InvalidArgumentException("$key: On or Off");
                }
                return $b ? 'On' : 'Off';
            case 'enum':
                if (!in_array($v, $x, true)) {
                    throw new \InvalidArgumentException("$key: invalid value");
                }
                return $v;
            case 'tz':
                if (!in_array($v, \DateTimeZone::listIdentifiers(), true)) {
                    throw new \InvalidArgumentException("$key: unknown time zone");
                }
                return $v;
            case 'charset':
                if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $v)) {
                    throw new \InvalidArgumentException("$key: invalid charset");
                }
                return $v;
        }
        throw new \LogicException('type');
    }

    /** @return array<string,string> validated user settings (empty values = server default, dropped) */
    public static function userSettings(array $in): array
    {
        $out = [];
        foreach (array_slice($in, 0, 60, true) as $k => $v) {
            if ((string)$v === '') {
                continue;
            }
            $out[(string)$k] = self::normalize((string)$k, $v);
        }
        return $out;
    }

    /** Admin extra lines "key = value": anything well-formed except the FORBIDDEN keys. */
    public static function adminLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('/^([a-z][a-z0-9_.]{1,60})\s*=\s*(.{0,200})$/i', $line, $m)) {
                throw new \InvalidArgumentException('Line ' . ($n + 1) . ': expected "directive = value"');
            }
            $k = strtolower($m[1]);
            $v = trim($m[2], " \t\"'");
            if (in_array($k, self::FORBIDDEN, true)) {
                throw new \InvalidArgumentException("Line " . ($n + 1) . ": $k is managed by the panel and cannot be set here");
            }
            if (preg_match('/[\r\n\0\[\]]/', $v)) {
                throw new \InvalidArgumentException('Line ' . ($n + 1) . ': invalid characters');
            }
            $out[$k] = $v;
            if (count($out) > 50) {
                throw new \InvalidArgumentException('Too many lines (max 50)');
            }
        }
        return $out;
    }

    /** Pool lines for a domain. */
    public static function poolLines(array $user, array $admin): string
    {
        $s = '';
        foreach (self::userSettings($user) as $k => $v) {
            $s .= "php_value[$k] = $v\n";
        }
        foreach ($admin as $k => $v) {
            $s .= "php_admin_value[$k] = $v\n";
        }
        return $s;
    }
}
