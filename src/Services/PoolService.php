<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\DB;

/**
 * PHP-FPM pool and resource limits of a site.
 * Stored as JSON in domains.php_pool; every value may be null = automatic:
 *   ram_mb / cpu_percent   null = the owner's package (1024 MB / 100 % without a package), 0 = unlimited (admins only)
 *   pm                     ondemand | dynamic | static
 *   max_children           null = RAM / 64 (2..50; unlimited RAM: server RAM / 64, at most 100)
 *   start, min_spare, max_spare   dynamic only (null = derived from max_children)
 *   idle_timeout           seconds an idle worker lives (ondemand)
 *   max_requests           requests before a worker is replaced (0 = never)
 */
final class PoolService
{
    public const KEYS = ['ram_mb', 'cpu_percent', 'pm', 'max_children', 'start', 'min_spare', 'max_spare', 'idle_timeout', 'max_requests'];
    public const MODES = ['ondemand', 'dynamic', 'static'];
    public const DEFAULT_RAM = 1024;
    public const DEFAULT_CPU = 100;

    /** Stored values (null = automatic). */
    public static function stored(array $d): array
    {
        $j = json_decode((string)($d['php_pool'] ?? ''), true) ?: [];
        return array_merge(array_fill_keys(self::KEYS, null), array_intersect_key($j, array_flip(self::KEYS)));
    }

    /** The package's limits of the site's owner (or the defaults). */
    public static function packageLimits(array $owner): array
    {
        $pkg = !empty($owner['package_id']) ? DB::one('SELECT ram_mb, cpu_percent, name FROM packages WHERE id = ?', [$owner['package_id']]) : null;
        return ['ram_mb' => (int)($pkg['ram_mb'] ?? self::DEFAULT_RAM), 'cpu_percent' => (int)($pkg['cpu_percent'] ?? self::DEFAULT_CPU), 'package' => $pkg['name'] ?? null];
    }

    /** What the helper gets: effective limits (0 = unlimited) and the pool settings (null = the helper derives them). */
    public static function effective(array $d, array $owner): array
    {
        $s = self::stored($d);
        $p = self::packageLimits($owner);
        return [
            'limits' => ['ram_mb' => $s['ram_mb'] ?? $p['ram_mb'], 'cpu_percent' => $s['cpu_percent'] ?? $p['cpu_percent']],
            'pool' => ['pm' => $s['pm'] ?? 'ondemand', 'max_children' => $s['max_children'], 'start' => $s['start'], 'min_spare' => $s['min_spare'],
                'max_spare' => $s['max_spare'], 'idle_timeout' => $s['idle_timeout'] ?? 10, 'max_requests' => $s['max_requests'] ?? 500],
        ];
    }

    /** max_children the helper will use when it is automatic (same formula as Fpm::children()). */
    public static function autoChildren(int $ramMb): int
    {
        if ($ramMb <= 0) {
            $total = (int)(preg_match('/MemTotal:\s+(\d+)/', (string)@file_get_contents('/proc/meminfo'), $m) ? $m[1] / 1024 : 4096);
            return max(2, min(100, intdiv($total, 64)));
        }
        return max(2, min(50, intdiv($ramMb, 64)));
    }

    /**
     * Validates form input (strings; '' = automatic / package). Throws \InvalidArgumentException with a translated message.
     * @return array stored form
     */
    public static function validate(array $in): array
    {
        $num = static function (string $k, int $min, int $max) use ($in): ?int {
            $v = trim((string)($in[$k] ?? ''));
            if ($v === '') {
                return null;
            }
            if (!preg_match('/^\d+$/D', $v) || (int)$v < $min || (int)$v > $max) {
                throw new \InvalidArgumentException(t('pool.e_range', ['f' => t('pool.' . $k), 'min' => $min, 'max' => $max]));
            }
            return (int)$v;
        };
        $out = [
            'ram_mb' => !empty($in['ram_unlimited']) ? 0 : $num('ram_mb', 128, 1048576),
            'cpu_percent' => !empty($in['cpu_unlimited']) ? 0 : $num('cpu_percent', 10, 12800),
            'pm' => in_array($in['pm'] ?? '', self::MODES, true) ? $in['pm'] : null,
            'max_children' => $num('max_children', 1, 1000),
            'start' => $num('start', 1, 1000), 'min_spare' => $num('min_spare', 1, 1000), 'max_spare' => $num('max_spare', 1, 1000),
            'idle_timeout' => $num('idle_timeout', 1, 3600),
            'max_requests' => $num('max_requests', 0, 100000),
        ];
        if ($out['pm'] === 'ondemand') {
            $out['pm'] = null;                         // the default
        }
        if (($out['pm'] ?? 'ondemand') !== 'dynamic') {
            $out['start'] = $out['min_spare'] = $out['max_spare'] = null;
        } elseif ($out['min_spare'] !== null && $out['max_spare'] !== null && $out['min_spare'] > $out['max_spare']) {
            throw new \InvalidArgumentException(t('pool.e_spare'));
        } elseif ($out['start'] !== null && (($out['min_spare'] !== null && $out['start'] < $out['min_spare']) || ($out['max_spare'] !== null && $out['start'] > $out['max_spare']))) {
            throw new \InvalidArgumentException(t('pool.e_start'));
        }
        if ($out['max_children'] !== null) {
            foreach (['start', 'max_spare'] as $k) {
                if ($out[$k] !== null && $out[$k] > $out['max_children']) {
                    throw new \InvalidArgumentException(t('pool.e_children'));
                }
            }
        }
        return $out;
    }

    public static function encode(array $stored): ?string
    {
        $stored = array_filter($stored, static fn($v) => $v !== null);
        return $stored ? json_encode($stored) : null;
    }
}
