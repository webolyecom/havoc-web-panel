<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/** Read-only measurements: disk/inode usage per hosting account, live FPM usage per domain, traffic from the access logs. */
final class Usage
{
    private const LOGDIR = '/var/log/havoc/domains';
    private const MONTHS = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];

    public static function scan(array $a): array
    {
        $out = [];
        foreach (array_slice((array)($a['accounts'] ?? []), 0, 2000) as $acct) {
            $acct = Validator::account($acct);
            $home = "/home/$acct";
            if (!is_dir($home)) {
                continue;
            }
            try {
                $disk = (int)Sys::run(['du', '-sx', '--block-size=1', $home], null, 900)[1];       // "bytes<TAB>path"; du never follows symlinks
                $inodes = (int)Sys::run(['du', '-sx', '--inodes', $home], null, 900)[1];
                $out[$acct] = ['disk' => $disk, 'inodes' => $inodes];
            } catch (\Throwable) {
                // a timed-out tree is skipped; the previous measurement stays in the panel
            }
        }
        return ['usage' => $out];
    }

    /** Live memory / CPU time of each domain's PHP-FPM unit (cgroup accounting). */
    public static function fpm(array $a): array
    {
        $out = [];
        foreach (array_slice((array)($a['ids'] ?? []), 0, 200) as $id) {
            $id = Validator::id($id);
            [, $o] = Sys::run(['systemctl', 'show', "havoc-fpm-$id", '-p', 'ActiveState', '-p', 'MemoryCurrent', '-p', 'MemoryMax', '-p', 'CPUUsageNSec']);
            $p = [];
            foreach (explode("\n", trim($o)) as $l) {
                [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
                $p[$k] = $v;
            }
            $num = static fn($v) => ctype_digit((string)$v) && (string)$v !== '18446744073709551615' ? (int)$v : null;
            $out[$id] = ['active' => $p['ActiveState'] ?? '', 'mem' => $num($p['MemoryCurrent'] ?? ''), 'mem_max' => $num($p['MemoryMax'] ?? ''), 'cpu_s' => ($c = $num($p['CPUUsageNSec'] ?? '')) === null ? null : round($c / 1e9, 1)];
        }
        return ['fpm' => $out];
    }

    /**
     * Incremental access-log parsing. The panel keeps the byte offset per domain and passes it back; if the file shrank it was
     * rotated, so the unread tail of <log>.1 is read first. Returns per-day aggregates and the new offset.
     */
    public static function traffic(array $a): array
    {
        $res = [];
        foreach (array_slice((array)($a['items'] ?? []), 0, 500) as $it) {
            $dom = Validator::domain($it['domain'] ?? '');
            $off = max(0, (int)($it['offset'] ?? 0));
            $file = self::LOGDIR . "/$dom.access.log";
            $days = [];
            if (!is_file($file)) {
                $res[$dom] = ['days' => [], 'offset' => 0];
                continue;
            }
            $size = (int)filesize($file);
            $budget = 64 * 1024 * 1024;
            if ($off > $size) {
                $prev = $file . '.1';
                if (is_file($prev) && $off <= (int)filesize($prev)) {
                    $budget -= self::chunk($prev, $off, (int)filesize($prev), $budget, $days);
                }
                $off = 0;
            }
            $off += self::chunk($file, $off, $size, max(0, $budget), $days);
            $res[$dom] = ['days' => $days, 'offset' => $off];
        }
        return ['traffic' => $res];
    }

    /** Reads complete lines from $from up to $to (max $budget bytes). Returns the number of bytes consumed. */
    private static function chunk(string $f, int $from, int $to, int $budget, array &$days): int
    {
        $limit = min($budget, $to - $from);
        if ($limit <= 0 || !($fh = fopen($f, 'rb'))) {
            return 0;
        }
        fseek($fh, $from);
        $read = 0;
        $consumed = 0;
        $buf = '';
        while ($read < $limit) {
            $d = fread($fh, min(1048576, $limit - $read));
            if ($d === '' || $d === false) {
                break;
            }
            $read += strlen($d);
            $buf .= $d;
            $p = strrpos($buf, "\n");
            if ($p === false) {
                continue;
            }
            $block = substr($buf, 0, $p);
            $buf = substr($buf, $p + 1);
            $consumed += $p + 1;
            foreach (explode("\n", $block) as $line) {
                self::line($line, $days);
            }
        }
        fclose($fh);
        return $consumed;
    }

    private static function line(string $l, array &$days): void
    {
        if (!preg_match('#\[(\d{2})/([A-Za-z]{3})/(\d{4}):\d{2}:\d{2}:\d{2} [+-]\d{4}\] "[^"]*" (\d{3}) (\d+|-)#', $l, $m) || !isset(self::MONTHS[$m[2]])) {
            return;
        }
        $day = $m[3] . '-' . self::MONTHS[$m[2]] . '-' . $m[1];
        $days[$day] ??= ['bytes' => 0, 'hits' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 's5' => 0];
        $days[$day]['bytes'] += $m[5] === '-' ? 0 : (int)$m[5];
        $days[$day]['hits']++;
        $k = 's' . $m[4][0];
        if (isset($days[$day][$k])) {
            $days[$day][$k]++;
        }
    }
}
