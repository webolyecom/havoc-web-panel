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
        $ram  = max(128, (int)($vh['limits']['ram_mb'] ?? 1024));
        $cpu  = max(10, (int)($vh['limits']['cpu_percent'] ?? 100));
        $children = max(2, min(50, intdiv($ram, 64)));
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
pm = ondemand
pm.max_children = $children
pm.process_idle_timeout = 10s
pm.max_requests = 500
catch_workers_output = yes
php_admin_value[open_basedir] = $home/:/tmp/:/usr/share/php/
php_admin_value[upload_tmp_dir] = $home/tmp
php_admin_value[sys_temp_dir] = $home/tmp
php_admin_value[session.save_path] = $home/tmp
php_admin_value[disable_functions] = $disabled
php_admin_value[expose_php] = off
php_admin_value[opcache.enable] = {$op['enable']}
php_admin_value[opcache.memory_consumption] = {$op['memory']}
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 2

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
        $mem = $ram . 'M';
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
CPUQuota={$cpu}%
Restart=on-failure
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
}
