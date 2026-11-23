<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\Audit;
use Havoc\Core\Ctl;
use Havoc\Core\DB;
use Havoc\Core\Settings;

/**
 * Configuration → PHP versions: installed versions with their support dates and the sites using them, installing a new
 * one and removing one nobody needs. Also the warnings for sites on a version whose support ended or ends soon
 * (dashboard, the site's PHP page).
 */
final class PhpVersionService
{
    /** version => [active support until, security fixes until] (php.net / endoflife.date) */
    public const SUPPORT = [
        '7.4' => ['2021-11-28', '2022-11-28'],
        '8.0' => ['2022-11-26', '2023-11-26'],
        '8.1' => ['2023-11-25', '2025-12-31'],
        '8.2' => ['2024-12-31', '2026-12-31'],
        '8.3' => ['2025-12-31', '2027-12-31'],
        '8.4' => ['2026-12-31', '2028-12-31'],
        '8.5' => ['2027-12-31', '2029-12-31'],
        '8.6' => ['2028-12-31', '2030-12-31'],
    ];
    public const SOON_DAYS = 90;

    /** @return array{state:string, active:?string, security:?string, days:?int} state: active | security | soon | ended | unknown */
    public static function support(string $v): array
    {
        [$active, $sec] = self::SUPPORT[$v] ?? [null, null];
        if ($sec === null) {
            return ['state' => 'unknown', 'active' => null, 'security' => null, 'days' => null];
        }
        $today = strtotime('today');
        $days = (int)floor((strtotime($sec . ' 23:59:59') - $today) / 86400);
        $state = $days < 0 ? 'ended' : ($days <= self::SOON_DAYS ? 'soon' : (strtotime($active . ' 23:59:59') >= $today ? 'active' : 'security'));
        return ['state' => $state, 'active' => $active, 'security' => $sec, 'days' => $days];
    }

    /** Sites per PHP version (every state: a suspended site still needs its version). */
    public static function usage(): array
    {
        $out = [];
        foreach (DB::all("SELECT id, name, php_version FROM domains WHERE type <> 'alias' ORDER BY name") as $d) {
            $out[$d['php_version']][] = $d;
        }
        return $out;
    }

    public static function overview(): array
    {
        $o = Ctl::call('php.overview', [], 120);
        $use = self::usage();
        foreach ($o['installed'] as &$i) {
            $v = $i['version'];
            $i['support'] = self::support($v);
            $i['sites'] = $use[$v] ?? [];
            $i['blocked'] = $v === $o['panel'] ? 'panel' : ($v === $o['cli'] ? 'cli' : ($i['sites'] ? 'sites' : (count($o['installed']) <= 1 ? 'last' : '')));
        }
        unset($i);
        $o['available'] = array_map(static fn($v) => ['version' => $v, 'support' => self::support($v)], $o['available']);
        return $o;
    }

    public static function install(array $actor, string $v): void
    {
        Ctl::call('php.install', ['version' => $v]);
        Audit::log('php.install', $v, [], $actor);
    }

    public static function remove(array $actor, string $v): void
    {
        if ($n = count(self::usage()[$v] ?? [])) {
            throw new \DomainException(t('phpv.e_in_use', ['v' => $v, 'n' => $n]));
        }
        Ctl::call('php.remove', ['version' => $v]);
        Audit::log('php.remove', $v, [], $actor);
    }

    /**
     * Dashboard notice: sites on a PHP version whose security support ended or ends within 90 days. Null when there is
     * none or the admin closed this very notice (it comes back when the list changes).
     * @return ?array{versions:array<string,array{n:int,support:array}>, sig:string}
     */
    public static function notice(): ?array
    {
        $vs = [];
        foreach (self::usage() as $v => $sites) {
            $s = self::support((string)$v);
            if (in_array($s['state'], ['ended', 'soon'], true)) {
                $vs[(string)$v] = ['n' => count($sites), 'support' => $s];
            }
        }
        if (!$vs) {
            return null;
        }
        ksort($vs, SORT_NATURAL);
        $sig = sha1(json_encode(array_map(static fn($x) => [$x['n'], $x['support']['state']], $vs)));
        return Settings::get('php.eol_dismissed') === $sig ? null : ['versions' => $vs, 'sig' => $sig];
    }

    public static function dismiss(string $sig): void
    {
        if (preg_match('/^[a-f0-9]{40}$/', $sig)) {
            Settings::set('php.eol_dismissed', $sig);
        }
    }
}
