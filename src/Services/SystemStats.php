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
}
