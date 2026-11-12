<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * MariaDB performance settings (server-wide, admin only). Written to their own file, so the Debian defaults stay untouched.
 * A change restarts MariaDB; if it does not come back, the previous file is restored and MariaDB restarted again.
 */
final class MariaTune
{
    private const FILE = '/etc/mysql/mariadb.conf.d/90-havoc-tuning.cnf';

    /** name => [unit, min, max]; unit: mb (written as <n>M), int, float, bool, enum */
    public const KEYS = [
        'innodb_buffer_pool_size'        => ['mb', 64, 262144],
        'innodb_log_file_size'           => ['mb', 48, 16384],
        'innodb_flush_log_at_trx_commit' => ['enum', ['1', '2', '0']],
        'innodb_io_capacity'             => ['int', 100, 50000],
        'max_connections'                => ['int', 10, 10000],
        'table_open_cache'               => ['int', 400, 100000],
        'table_definition_cache'         => ['int', 400, 100000],
        'thread_cache_size'              => ['int', 0, 1000],
        'tmp_table_size'                 => ['mb', 16, 8192],
        'max_heap_table_size'            => ['mb', 16, 8192],
        'max_allowed_packet'             => ['mb', 1, 1024],
        'wait_timeout'                   => ['int', 10, 86400],
        'slow_query_log'                 => ['bool'],
        'long_query_time'                => ['float', 0.1, 60],
    ];

    private static function memMb(): int
    {
        return preg_match('/MemTotal:\s+(\d+)/', (string)file_get_contents('/proc/meminfo'), $m) ? intdiv((int)$m[1], 1024) : 0;
    }

    private static function sql(string $q): string
    {
        $bin = is_file('/usr/bin/mariadb') ? '/usr/bin/mariadb' : '/usr/bin/mysql';
        return Sys::ok([$bin, '--batch', '--skip-column-names'], $q, 20);
    }

    /** Suggested values for a hosting server (MariaDB shares the RAM with PHP and the web server). */
    public static function preset(string $name, int $ram): array
    {
        $pct = ['light' => 0.2, 'balanced' => 0.35, 'database' => 0.6][$name] ?? 0.35;
        $bp = max(128, (int)(floor($ram * $pct / 64) * 64));
        $tier = $ram <= 2048 ? 0 : ($ram <= 8192 ? 1 : 2);
        return [
            'innodb_buffer_pool_size' => $bp, 'innodb_log_file_size' => max(48, min(2048, (int)(floor($bp / 4 / 16) * 16))),
            'innodb_flush_log_at_trx_commit' => '1', 'innodb_io_capacity' => 1000,
            'max_connections' => [100, 250, 500][$tier], 'table_open_cache' => [2000, 4000, 8000][$tier],
            'table_definition_cache' => [1400, 2000, 4000][$tier], 'thread_cache_size' => [16, 32, 64][$tier],
            'tmp_table_size' => [32, 64, 128][$tier], 'max_heap_table_size' => [32, 64, 128][$tier],
            'max_allowed_packet' => 64, 'wait_timeout' => 600, 'slow_query_log' => true, 'long_query_time' => 2,
        ];
    }

    public static function status(): array
    {
        $ram = self::memMb();
        $cur = [];
        try {
            $names = "'" . implode("','", array_keys(self::KEYS)) . "'";
            foreach (explode("\n", trim(self::sql("SHOW GLOBAL VARIABLES WHERE Variable_name IN ($names);\n"))) as $line) {
                [$k, $v] = array_pad(explode("\t", $line, 2), 2, '');
                if (!isset(self::KEYS[$k])) {
                    continue;
                }
                $cur[$k] = match (self::KEYS[$k][0]) {
                    'mb' => (int)round((int)$v / 1048576),
                    'bool' => in_array(strtoupper($v), ['ON', '1'], true),
                    'float' => (float)$v,
                    default => $v,
                };
            }
            [$up] = explode("\n", trim(self::sql("SHOW GLOBAL STATUS LIKE 'Uptime';\n")) . "\n");
            $uptime = (int)(explode("\t", $up)[1] ?? 0);
            $version = trim(self::sql("SELECT VERSION();\n"));
        } catch (\Throwable $e) {
            return ['ram_mb' => $ram, 'error' => $e->getMessage(), 'current' => [], 'file' => self::read(), 'presets' => self::presets($ram)];
        }
        return ['ram_mb' => $ram, 'current' => $cur, 'uptime' => $uptime, 'version' => $version, 'file' => self::read(), 'presets' => self::presets($ram)];
    }

    private static function presets(int $ram): array
    {
        return ['light' => self::preset('light', $ram), 'balanced' => self::preset('balanced', $ram), 'database' => self::preset('database', $ram)];
    }

    private static function read(): array
    {
        $out = [];
        foreach (is_file(self::FILE) ? (file(self::FILE, FILE_IGNORE_NEW_LINES) ?: []) : [] as $l) {
            if (preg_match('/^([a-z_]+)\s*=\s*(\S+)$/', $l, $m) && isset(self::KEYS[$m[1]])) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    public static function apply(array $a): array
    {
        $ram = self::memMb();
        $lines = [];
        foreach (self::KEYS as $k => $def) {
            if (!array_key_exists($k, $a) || $a[$k] === '' || $a[$k] === null) {
                continue;                                         // not set: MariaDB default
            }
            $v = $a[$k];
            switch ($def[0]) {
                case 'mb':
                    if (!preg_match('/^\d{1,6}$/', (string)$v) || (int)$v < $def[1] || (int)$v > $def[2]) {
                        throw new \InvalidArgumentException("$k: {$def[1]} - {$def[2]} MB");
                    }
                    $lines[] = "$k = " . (int)$v . 'M';
                    break;
                case 'int':
                    if (!preg_match('/^\d{1,7}$/', (string)$v) || (int)$v < $def[1] || (int)$v > $def[2]) {
                        throw new \InvalidArgumentException("$k: {$def[1]} - {$def[2]}");
                    }
                    $lines[] = "$k = " . (int)$v;
                    break;
                case 'float':
                    if (!is_numeric($v) || (float)$v < $def[1] || (float)$v > $def[2]) {
                        throw new \InvalidArgumentException("$k: {$def[1]} - {$def[2]}");
                    }
                    $lines[] = "$k = " . rtrim(rtrim(sprintf('%.3F', (float)$v), '0'), '.');
                    break;
                case 'bool':
                    $lines[] = "$k = " . (filter_var($v, FILTER_VALIDATE_BOOL) ? 'ON' : 'OFF');
                    break;
                case 'enum':
                    if (!in_array((string)$v, $def[1], true)) {
                        throw new \InvalidArgumentException("$k: invalid value");
                    }
                    $lines[] = "$k = $v";
                    break;
            }
        }
        if (isset($a['innodb_buffer_pool_size']) && $a['innodb_buffer_pool_size'] !== '' && (int)$a['innodb_buffer_pool_size'] > (int)($ram * 0.8)) {
            throw new \InvalidArgumentException('innodb_buffer_pool_size must not exceed 80 % of the RAM (' . (int)($ram * 0.8) . ' MB)');
        }
        if (in_array('slow_query_log = ON', $lines, true)) {
            $lines[] = 'slow_query_log_file = /var/log/mysql/mariadb-slow.log';
        }
        $txt = "# Managed by Havoc WEB Panel (MariaDB tuning). Edit in the panel.\n[mysqld]\n" . implode("\n", $lines) . "\n";
        $old = is_file(self::FILE) ? (string)file_get_contents(self::FILE) : null;
        Sys::write(self::FILE, $txt, 0644);
        is_dir('/var/log/mysql') || mkdir('/var/log/mysql', 0750, true);
        @chown('/var/log/mysql', 'mysql');
        [$c] = Sys::run(['systemctl', 'restart', 'mariadb'], null, 300);
        if ($c !== 0) {
            $log = trim(Sys::run(['journalctl', '-u', 'mariadb', '--no-pager', '-n', '8', '-o', 'cat'])[1]);
            $old === null ? @unlink(self::FILE) : Sys::write(self::FILE, $old, 0644);
            Sys::run(['systemctl', 'restart', 'mariadb'], null, 300);
            throw new \RuntimeException("MariaDB did not start with these settings, the previous ones were restored.\n" . mb_substr($log, -1200));
        }
        return ['lines' => count($lines)];
    }
}
