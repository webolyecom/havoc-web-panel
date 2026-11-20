<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Server → Resources: where the memory and CPU go.
 * - /proc/meminfo: total, available, reclaimable file cache, swap.
 * - Every systemd service from its cgroup (v2): memory of its programs ("anon" + kernel: what really counts) and file
 *   cache charged to it (reclaimable), CPU over a ~1 s sample, number of processes. Each site's PHP is its own service
 *   (havoc-fpm-<id>), so this is also the per-site view.
 * - Without cgroup v2: the biggest processes grouped by program name.
 */
final class Resources
{
    public static string $cg = '/sys/fs/cgroup';

    public static function snapshot(): array
    {
        $mem = self::meminfo();
        $a = self::units();
        $t = microtime(true);
        usleep(1000000);
        $b = self::units();
        $dt = max(0.1, microtime(true) - $t);
        $units = [];
        foreach ($b as $name => $u) {
            $u['cpu'] = isset($a[$name]['cpu_usec'], $u['cpu_usec']) ? round(($u['cpu_usec'] - $a[$name]['cpu_usec']) / 1e6 / $dt * 100, 1) : null;
            unset($u['cpu_usec']);
            $units[$name] = $u;
        }
        uasort($units, static fn($x, $y) => $y['memory'] <=> $x['memory']);
        return ['mem' => $mem, 'units' => $units, 'cgroup' => $units !== [], 'procs' => $units ? [] : self::processes(),
            'cpus' => (int)(preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo')) ?: 1),
            'load' => array_map('floatval', array_slice(explode(' ', (string)@file_get_contents('/proc/loadavg')), 0, 3))];
    }

    /** bytes */
    public static function meminfo(): array
    {
        $m = [];
        foreach (explode("\n", (string)@file_get_contents('/proc/meminfo')) as $l) {
            if (preg_match('/^(\w+):\s+(\d+)/', $l, $x)) {
                $m[$x[1]] = (int)$x[2] * 1024;
            }
        }
        $total = $m['MemTotal'] ?? 0;
        $avail = $m['MemAvailable'] ?? 0;
        $cache = ($m['Cached'] ?? 0) + ($m['Buffers'] ?? 0) + ($m['SReclaimable'] ?? 0) - ($m['Shmem'] ?? 0);
        return ['total' => $total, 'available' => $avail, 'used' => $total - $avail, 'free' => $m['MemFree'] ?? 0,
            'cache' => max(0, $cache), 'shmem' => $m['Shmem'] ?? 0,
            'swap_total' => $m['SwapTotal'] ?? 0, 'swap_used' => ($m['SwapTotal'] ?? 0) - ($m['SwapFree'] ?? 0)];
    }

    /** Services of system.slice (cgroup v2). */
    private static function units(): array
    {
        $out = [];
        foreach (glob(self::$cg . '/system.slice/*.service', GLOB_ONLYDIR) ?: [] as $dir) {
            $st = [];
            foreach (explode("\n", (string)@file_get_contents("$dir/memory.stat")) as $l) {
                [$k, $v] = array_pad(explode(' ', $l, 2), 2, '0');
                $st[$k] = (int)$v;
            }
            if (!$st) {
                continue;
            }
            $cpu = preg_match('/^usage_usec (\d+)/m', (string)@file_get_contents("$dir/cpu.stat"), $c) ? (int)$c[1] : null;
            $max = trim((string)@file_get_contents("$dir/memory.max"));
            $memory = ($st['anon'] ?? 0) + ($st['kernel'] ?? (($st['kernel_stack'] ?? 0) + ($st['slab_unreclaimable'] ?? 0))) + ($st['shmem'] ?? 0);
            $out[basename($dir, '.service')] = [
                'memory' => $memory,                                      // what the service really holds
                'cache' => max(0, ($st['file'] ?? 0) - ($st['shmem'] ?? 0)), // reclaimable when memory gets short
                'swap' => (int)trim((string)@file_get_contents("$dir/memory.swap.current")),
                'limit' => ctype_digit($max) ? (int)$max : null,
                'procs' => (int)trim((string)@file_get_contents("$dir/pids.current")),
                'cpu_usec' => $cpu,
            ];
        }
        return array_filter($out, static fn($u) => $u['memory'] > 0 || $u['procs'] > 0);
    }

    /** Fallback: resident memory of the biggest programs (summed by name). */
    private static function processes(): array
    {
        [$c, $o] = Sys::run(['ps', '-eo', 'rss=,comm=', '--sort=-rss']);
        $by = [];
        if ($c === 0) {
            foreach (explode("\n", trim((string)$o)) as $l) {
                if (preg_match('/^\s*(\d+)\s+(.+)$/', $l, $x) && (int)$x[1] > 0) {   // kernel threads have no memory
                    $n = trim($x[2]);
                    $by[$n] = ['memory' => ($by[$n]['memory'] ?? 0) + (int)$x[1] * 1024, 'procs' => ($by[$n]['procs'] ?? 0) + 1];
                }
            }
        }
        uasort($by, static fn($x, $y) => $y['memory'] <=> $x['memory']);
        return array_slice($by, 0, 15, true);
    }
}
