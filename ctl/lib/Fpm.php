<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * One PHP-FPM master per domain (systemd unit havoc-fpm-<id>). This is what makes per-domain PHP version,
 * per-domain extension set (own PHP_INI_SCAN_DIR), per-domain OPcache and cgroup limits possible.
 */
final class Fpm
{
    private const DISABLED = 'exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,show_source,dl,symlink,link';

    public static function installedVersions(): array
    {
        return array_values(array_filter(Validator::PHP_VERSIONS, static fn($v) => is_file("/usr/sbin/php-fpm$v")));
    }

    /** Extensions available for a PHP version (from Debian mods-available), with their load priority. */
    public static function availableExtensions(string $ver): array
    {
        $out = [];
        foreach (glob("/etc/php/$ver/mods-available/*.ini") ?: [] as $f) {
            $name = basename($f, '.ini');
            $prio = 20;
            if (preg_match('/^;\s*priority=(\d+)/m', (string)file_get_contents($f), $m)) {
                $prio = (int)$m[1];
            }
            $out[$name] = $prio;
        }
        return $out;
    }

    public static function defaultExtensions(string $ver): array
    {
        $names = [];
        foreach (glob("/etc/php/$ver/fpm/conf.d/*.ini") ?: [] as $f) {
            $names[] = preg_replace('/^\d+-/', '', basename($f, '.ini'));
        }
        return $names;
    }

    public static function apply(array $vh): void
    {
        $id   = Validator::id($vh['id']);
        $ver  = Validator::php($vh['php']);
        $acct = Validator::account($vh['account']);
        $dom  = Validator::domain($vh['domain']);
        if (!is_file("/usr/sbin/php-fpm$ver")) {
            throw new \RuntimeException("PHP $ver is not installed");
        }
        $home = "/home/$acct";
        $ramIn = (int)($vh['limits']['ram_mb'] ?? 1024);
        $cpuIn = (int)($vh['limits']['cpu_percent'] ?? 100);
        $ram  = $ramIn === 0 ? 0 : max(128, min(1048576, $ramIn));        // 0 = unlimited
        $cpu  = $cpuIn === 0 ? 0 : max(10, min(12800, $cpuIn));
        $pm   = self::poolLines((array)($vh['pool'] ?? []), $ram);
        $op   = $vh['opcache'] ?? ['enable' => 1, 'memory' => 128];

        // extension set: symlink only the enabled ones into a private scan dir
        $scan = "/etc/havoc/php/$id/conf.d";
        Sys::run(['rm', '-rf', $scan]);
        mkdir($scan, 0755, true);
        $avail = self::availableExtensions($ver);
        $want  = isset($vh['extensions']) && is_array($vh['extensions'])
            ? array_map([Validator::class, 'ext'], $vh['extensions']) : self::defaultExtensions($ver);
        foreach ($want as $ext) {
            if (isset($avail[$ext])) {
                symlink("/etc/php/$ver/mods-available/$ext.ini", sprintf('%s/%02d-%s.ini', $scan, $avail[$ext], $ext));
            }
        }

        // OPcache at startup (read once by this site's own FPM master). Not as php_admin_value in the pool: FPM applies those
        // again in every worker at runtime, and OPcache may only be switched off at runtime, so "enable = 1" there logged
        // "Zend OPcache can't be temporary enabled" for every new worker.
        Sys::write("$scan/99-havoc-opcache.ini", sprintf("; written by Havoc WEB Panel\nopcache.enable = %d\nopcache.memory_consumption = %d\nopcache.validate_timestamps = 1\nopcache.revalidate_freq = 2\n",
            (int)$op['enable'], (int)$op['memory']), 0644);

        // security defaults read at startup; a site may still change them in its .user.ini or PHP settings.
        // Upload progress stores attacker-shaped data in the session file (used by CVE-2026-126714 "phpAfterShell" and
        // CVE-2026-115560); almost no site uses it.
        Sys::write("$scan/98-havoc-security.ini", "; written by Havoc WEB Panel\nsession.upload_progress.enabled = 0\n", 0644);

        $disabled = self::DISABLED;
        $pool = <<<INI
[global]
pid = /run/havoc-fpm/$id.pid
error_log = /var/log/havoc/domains/$dom.php-error.log
daemonize = no

[domain]
user = $acct
group = $acct
listen = /run/havoc-fpm/$id.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
{$pm}catch_workers_output = yes
php_admin_value[open_basedir] = $home/:/tmp/:/usr/share/php/
php_admin_value[upload_tmp_dir] = $home/tmp
php_admin_value[sys_temp_dir] = $home/tmp
php_admin_value[session.save_path] = $home/tmp
php_admin_value[disable_functions] = $disabled
php_admin_value[expose_php] = off

INI;
        $pool .= PhpIni::poolLines((array)($vh['php_ini'] ?? []), PhpIni::adminLines((string)($vh['php_ini_admin'] ?? '')));
        $poolFile = "/etc/havoc/fpm/$id.conf";
        $oldPool = is_file($poolFile) ? (string)file_get_contents($poolFile) : null;
        Sys::write($poolFile, $pool);
        [$tc, $to, $te] = Sys::run(["/usr/sbin/php-fpm$ver", '-t', '--fpm-config', $poolFile], null, 30, ['PHP_INI_SCAN_DIR' => $scan]);
        if ($tc !== 0) {
            $oldPool === null ? @unlink($poolFile) : Sys::write($poolFile, $oldPool);
            throw new \RuntimeException('PHP-FPM configuration test failed: ' . trim(preg_replace('/\[\d{2}-\w{3}-\d{4} [\d:]+\] /', '', $te ?: $to)));
        }
        $mem = $ram > 0 ? $ram . 'M' : 'infinity';
        $cpuLine = $cpu > 0 ? "CPUQuota={$cpu}%\n" : '';
        $unit = <<<UNIT
[Unit]
Description=Havoc PHP-FPM $ver for $dom
After=network.target

[Service]
Type=simple
Environment=PHP_INI_SCAN_DIR=$scan
ExecStart=/usr/sbin/php-fpm$ver --nodaemonize --fpm-config /etc/havoc/fpm/$id.conf
ExecReload=/bin/kill -USR2 \$MAINPID
MemoryMax=$mem
{$cpuLine}Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target

UNIT;
        Sys::write("/etc/systemd/system/havoc-fpm-$id.service", $unit);
        Sys::ok(['systemctl', 'daemon-reload']);
        Sys::ok(['systemctl', 'enable', "havoc-fpm-$id"]);
        Sys::ok(['systemctl', 'restart', "havoc-fpm-$id"]);   // restart also resets this domain's OPcache
    }

    public static function remove(int $id): void
    {
        Sys::run(['systemctl', 'disable', '--now', "havoc-fpm-$id"]);
        @unlink("/etc/systemd/system/havoc-fpm-$id.service");
        @unlink("/etc/havoc/fpm/$id.conf");
        Sys::run(['rm', '-rf', "/etc/havoc/php/$id"]);
        Sys::run(['systemctl', 'daemon-reload']);
    }
    /** max_children when it is automatic: RAM / 64 (2..50); without a RAM limit server RAM / 64 (at most 100). */
    public static function autoChildren(int $ramMb): int
    {
        if ($ramMb <= 0) {
            $total = preg_match('/MemTotal:\s+(\d+)/', (string)@file_get_contents('/proc/meminfo'), $m) ? intdiv((int)$m[1], 1024) : 4096;
            return max(2, min(100, intdiv($total, 64)));
        }
        return max(2, min(50, intdiv($ramMb, 64)));
    }

    /** pm.* lines of a pool (values checked again here; null = derived). */
    public static function poolLines(array $p, int $ramMb): string
    {
        $int = static function ($v, int $min, int $max, ?int $def): ?int {
            if ($v === null || $v === '') {
                return $def;
            }
            if (!is_numeric($v) || (int)$v < $min || (int)$v > $max) {
                throw new \InvalidArgumentException("PHP-FPM value $v is out of range ($min-$max)");
            }
            return (int)$v;
        };
        $mode = in_array($p['pm'] ?? 'ondemand', ['ondemand', 'dynamic', 'static'], true) ? ($p['pm'] ?? 'ondemand') : 'ondemand';
        $max = $int($p['max_children'] ?? null, 1, 1000, self::autoChildren($ramMb));
        $out = "pm = $mode\npm.max_children = $max\n";
        if ($mode === 'dynamic') {
            $min = $int($p['min_spare'] ?? null, 1, 1000, max(1, intdiv($max, 8)));
            $maxS = $int($p['max_spare'] ?? null, 1, 1000, max($min, intdiv($max, 4)));
            $start = $int($p['start'] ?? null, 1, 1000, $min + intdiv($maxS - $min, 2));
            if ($min > $maxS || $start < $min || $start > $maxS || $maxS > $max) {
                throw new \InvalidArgumentException("PHP-FPM: min spare <= start <= max spare <= max children is required ($min/$start/$maxS/$max)");
            }
            $out .= "pm.start_servers = $start\npm.min_spare_servers = $min\npm.max_spare_servers = $maxS\n";
        } elseif ($mode === 'ondemand') {
            $out .= 'pm.process_idle_timeout = ' . $int($p['idle_timeout'] ?? null, 1, 3600, 10) . "s\n";
        }
        return $out . 'pm.max_requests = ' . $int($p['max_requests'] ?? null, 0, 100000, 500) . "\n";
    }
}
