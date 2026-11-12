<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Web logs: per-domain access / error / PHP error logs in /var/log/havoc/domains, plus a fixed whitelist of server logs (admin).
 * Logs are only ever addressed as (domain, type) or a whitelisted system key, never by a path from the request.
 * Clearing truncates the live file (the web server keeps writing to the same file handle) and deletes rotated copies.
 */
final class Logs
{
    private const DIR = '/var/log/havoc/domains';
    public const TYPES = ['access' => 'access', 'error' => 'error', 'php' => 'php-error'];
    public const SYSTEM = [
        'nginx-error'  => '/var/log/nginx/error.log',
        'nginx-access' => '/var/log/nginx/access.log',
        'panel-php'    => '/var/log/havoc/panel/php-fpm.log',
        'panel-nginx'  => '/var/log/havoc/panel/nginx-error.log',
        'mariadb'      => '/var/log/mysql/error.log',
        'mariadb-slow' => '/var/log/mysql/mariadb-slow.log',
        'waf'          => '/var/log/havoc/waf/audit.log',
    ];

    /** Resolve a log reference to a fixed path. */
    public static function path(array $a): string
    {
        if (!empty($a['system'])) {
            return self::SYSTEM[(string)$a['system']] ?? throw new \InvalidArgumentException('Unknown server log');
        }
        $dom = Validator::domain($a['domain'] ?? '');
        $t = self::TYPES[(string)($a['type'] ?? '')] ?? throw new \InvalidArgumentException('Bad log type');
        return self::DIR . "/$dom.$t.log";
    }

    /** @return string[] rotated copies of a log (logrotate: .1, .2.gz, ...) */
    private static function rotated(string $file): array
    {
        return array_values(array_filter(glob($file . '.*') ?: [], static fn($f) => preg_match('/\.\d+(\.gz)?$/', $f) === 1));
    }

    private static function info(string $file): array
    {
        $rot = self::rotated($file);
        $rsize = 0;
        foreach ($rot as $r) {
            $rsize += (int)@filesize($r);
        }
        return ['exists' => is_file($file), 'size' => is_file($file) ? (int)filesize($file) : 0, 'mtime' => is_file($file) ? (int)filemtime($file) : null,
            'rotated' => count($rot), 'rotated_size' => $rsize];
    }

    public static function list(array $a): array
    {
        $out = [];
        foreach (array_slice((array)($a['domains'] ?? []), 0, 2000) as $d) {
            $d = Validator::domain($d);
            foreach (array_keys(self::TYPES) as $t) {
                $out[$d][$t] = self::info(self::path(['domain' => $d, 'type' => $t]));
            }
        }
        $sys = [];
        if (!empty($a['system'])) {
            foreach (self::SYSTEM as $k => $f) {
                $sys[$k] = self::info($f) + ['path' => $f];
            }
        }
        return ['domains' => $out, 'system' => $sys];
    }

    /** Truncate the given logs and (optionally) delete their rotated copies. */
    public static function clear(array $a): array
    {
        $files = [];
        foreach (array_slice((array)($a['items'] ?? []), 0, 6000) as $it) {
            $files[] = self::path((array)$it);
        }
        $n = 0;
        $freed = 0;
        foreach (array_unique($files) as $f) {
            if (is_file($f) && !is_link($f)) {
                $freed += (int)filesize($f);
                $h = fopen($f, 'r+');
                if ($h) {
                    ftruncate($h, 0);
                    fclose($h);
                    $n++;
                }
            }
            if (!empty($a['rotated'])) {
                foreach (self::rotated($f) as $r) {
                    if (is_file($r) && !is_link($r)) {
                        $freed += (int)filesize($r);
                        unlink($r);
                    }
                }
            }
        }
        return ['cleared' => $n, 'freed' => $freed];
    }

    /** Last lines of several domain logs (one type) for the "recent across all sites" view. */
    public static function tailMany(array $a): array
    {
        $type = (string)($a['type'] ?? 'error');
        $lines = max(5, min(200, (int)($a['lines'] ?? 30)));
        $out = [];
        foreach (array_slice((array)($a['domains'] ?? []), 0, 500) as $d) {
            $f = self::path(['domain' => Validator::domain($d), 'type' => $type]);
            if (!is_file($f) || filesize($f) === 0) {
                continue;
            }
            $out[$d] = ['mtime' => (int)filemtime($f), 'data' => self::tail($f, $lines)];
        }
        uasort($out, static fn($x, $y) => $y['mtime'] <=> $x['mtime']);
        return ['logs' => $out];
    }

    public static function tail(string $f, int $lines): string
    {
        $size = (int)filesize($f);
        $fh = fopen($f, 'rb');
        fseek($fh, max(0, $size - 64 * 1024 - $lines * 200));
        $chunk = (string)stream_get_contents($fh);
        fclose($fh);
        return mb_convert_encoding(implode("\n", array_slice(explode("\n", rtrim($chunk)), -$lines)), 'UTF-8', 'UTF-8');
    }

    /** Raw stream for downloads: "OK <size> <name>" then the bytes (see havocctl). */
    public static function catPassthrough(array $a): int
    {
        $f = self::path($a);
        if (!is_file($f) || is_link($f)) {
            echo "ERR log file does not exist\n";
            return 2;
        }
        $fh = fopen($f, 'rb');
        $name = !empty($a['system']) ? $a['system'] . '.log' : basename($f);
        $size = fstat($fh)['size'];
        echo 'OK ', $size, ' ', rawurlencode($name), "\n";
        // copy exactly $size bytes: the file may grow while we read it
        $left = $size;
        while ($left > 0 && !feof($fh)) {
            $buf = fread($fh, (int)min(262144, $left));
            if ($buf === false || $buf === '') {
                break;
            }
            $left -= strlen($buf);
            echo $buf;
        }
        fclose($fh);
        return 0;
    }
}
