<?php
declare(strict_types=1);

namespace Havoc\Services;

/** Reads /proc directly: no privileges needed. */
final class SystemStats
{
    private static function cpuTimes(): array
    {
        $l = explode(' ', preg_replace('/\s+/', ' ', trim((string)strtok((string)file_get_contents('/proc/stat'), "\n"))));
        $n = array_map('intval', array_slice($l, 1));
        return [array_sum($n), ($n[3] ?? 0) + ($n[4] ?? 0)];   // total, idle+iowait
    }

    public static function snapshot(): array
    {
        [$t1, $i1] = self::cpuTimes();
        usleep(200000);
        [$t2, $i2] = self::cpuTimes();
        $cpu = $t2 > $t1 ? round(100 * (1 - ($i2 - $i1) / ($t2 - $t1)), 1) : 0.0;

        $mem = [];
        foreach (file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $mem[$m[1]] = (int)$m[2] * 1024;
            }
        }
        $total = $mem['MemTotal'] ?? 0;
        $used  = $total - ($mem['MemAvailable'] ?? 0);

        $rx = $tx = 0;
        foreach (array_slice(file('/proc/net/dev') ?: [], 2) as $line) {
            [$if, $rest] = array_pad(explode(':', $line, 2), 2, '');
            if (trim($if) === 'lo') {
                continue;
            }
            $f = preg_split('/\s+/', trim($rest));
            $rx += (int)($f[0] ?? 0);
            $tx += (int)($f[8] ?? 0);
        }
        $dt = disk_total_space('/');
        $df = disk_free_space('/');
        return [
            'cpu' => $cpu, 'cores' => max(1, preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo'))),
            'load' => array_map('floatval', array_slice(explode(' ', (string)file_get_contents('/proc/loadavg')), 0, 3)),
            'mem' => ['total' => $total, 'used' => $used, 'pct' => $total ? round(100 * $used / $total, 1) : 0],
            'swap' => ['total' => $mem['SwapTotal'] ?? 0, 'used' => ($mem['SwapTotal'] ?? 0) - ($mem['SwapFree'] ?? 0)],
            'disk' => ['total' => $dt, 'used' => $dt - $df, 'pct' => $dt ? round(100 * ($dt - $df) / $dt, 1) : 0],
            'net' => ['rx' => $rx, 'tx' => $tx],
            'uptime' => (int)explode(' ', (string)file_get_contents('/proc/uptime'))[0],
            'time' => time(),
        ];
    }

    /** Facts about the server for the dashboard (cheap: no sampling, no root needed). */
    public static function info(): array
    {
        $os = '';
        if (preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m', (string)@file_get_contents('/etc/os-release'), $m)) {
            $os = $m[1];
        }
        $cpuinfo = (string)@file_get_contents('/proc/cpuinfo');
        $model = preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m) ? trim(preg_replace('/\s+/', ' ', $m[1])) : php_uname('m');
        $mem = [];
        foreach (file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $mem[$m[1]] = (int)$m[2] * 1024;
            }
        }
        $disks = [];
        foreach (['/' => '/', '/home' => '/home', '/var' => '/var'] as $label => $path) {
            $t = @disk_total_space($path);
            $f = @disk_free_space($path);
            if ($t && $f !== false) {
                $key = (string)(@stat($path)['dev'] ?? $path);        // same filesystem as one shown before: skip
                if (!isset($disks[$key])) {
                    $disks[$key] = ['mount' => $label, 'total' => $t, 'free' => $f, 'pct' => round(100 * ($t - $f) / $t, 1)];
                }
            }
        }
        $mysql = '';
        if (is_executable('/usr/bin/mariadb') || is_executable('/usr/sbin/mariadbd')) {
            $v = (string)@shell_exec('/usr/sbin/mariadbd --version 2>/dev/null');
            $mysql = preg_match('/Ver\s+([\d.]+)/', $v, $m) ? $m[1] : '';
        }
        return [
            'hostname' => gethostname() ?: '', 'os' => $os ?: php_uname('s'), 'kernel' => php_uname('r'), 'arch' => php_uname('m'),
            'cpu' => $model, 'cores' => max(1, preg_match_all('/^processor\s*:/m', $cpuinfo)),
            'mem_total' => $mem['MemTotal'] ?? 0, 'mem_used' => ($mem['MemTotal'] ?? 0) - ($mem['MemAvailable'] ?? 0),
            'swap_total' => $mem['SwapTotal'] ?? 0, 'swap_used' => ($mem['SwapTotal'] ?? 0) - ($mem['SwapFree'] ?? 0),
            'disks' => array_values($disks), 'ips' => self::ips(),
            'uptime' => (int)explode(' ', (string)@file_get_contents('/proc/uptime'))[0],
            'load' => array_map('floatval', array_slice(explode(' ', (string)@file_get_contents('/proc/loadavg')), 0, 3)),
            'php' => \Havoc\Services\DomainService::phpVersions(), 'mariadb' => $mysql, 'timezone' => date_default_timezone_get(), 'time' => time(),
        ];
    }

    /** Public addresses of the server's interfaces (private/link-local ones only when there is nothing else). */
    public static function ips(): array
    {
        $all = [];
        $j = json_decode((string)@shell_exec('ip -j addr show 2>/dev/null'), true);
        foreach (is_array($j) ? $j : [] as $if) {
            if (($if['ifname'] ?? '') === 'lo') {
                continue;
            }
            foreach ($if['addr_info'] ?? [] as $a) {
                if (($a['scope'] ?? '') === 'global' && !empty($a['local'])) {
                    $all[] = (string)$a['local'];
                }
            }
        }
        $public = array_values(array_filter($all, static fn($ip) => (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)));
        $list = $public ?: $all;
        usort($list, static fn($a, $b) => (int)str_contains($a, ':') <=> (int)str_contains($b, ':'));   // IPv4 first
        return array_slice(array_values(array_unique($list)), 0, 6);
    }
}
