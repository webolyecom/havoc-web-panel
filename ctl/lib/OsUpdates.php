<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Operating system updates (Monitoring → System updates).
 * - check(): `apt-get update` and the list of upgradable packages, saved to CACHE (read cheaply by the panel).
 *   "security": from a Debian security suite; "php": PHP packages (sury.org publishes PHP security releases in its normal
 *   suite, so every PHP update counts as important).
 * - upgrade(): starts bin/havoc-os-upgrade in its own unit (security = security + PHP packages only, all = apt-get upgrade);
 *   it restarts every PHP-FPM (the sites' own masters too) after PHP updates and runs check() again at the end.
 */
final class OsUpdates
{
    public const CACHE = '/var/lib/havoc/os-updates.json';
    public const UNIT = 'havoc-os-update';
    public const LOG = '/var/log/havoc/os-update.log';
    public const STATUS = '/var/log/havoc/os-update.status';

    /** "name/suite1,suite2 version arch [upgradable from: old]" lines of `apt list --upgradable` */
    public static function parse(string $out): array
    {
        $pk = [];
        foreach (explode("\n", $out) as $l) {
            if (!preg_match('#^([a-z0-9][a-z0-9+.-]*)/(\S+)\s+(\S+)\s+(\S+)(?:\s+\[upgradable from:\s*([^\]]+)\])?#', trim($l), $m)) {
                continue;
            }
            $pk[] = ['name' => $m[1], 'to' => $m[3], 'from' => trim($m[5] ?? ''),
                'security' => (bool)preg_match('/(^|,)[a-z]+-security(,|$)/', $m[2]),
                'php' => (bool)preg_match('/^(php\d|php-|libapache2-mod-php)/', $m[1])];
        }
        usort($pk, static fn($a, $b) => [!$a['security'] && !$a['php'], $a['name']] <=> [!$b['security'] && !$b['php'], $b['name']]);
        return $pk;
    }

    public static function check(): array
    {
        $env = ['DEBIAN_FRONTEND' => 'noninteractive', 'LC_ALL' => 'C'];
        [$c, , $e] = Sys::run(['apt-get', 'update', '-q', '-o', 'DPkg::Lock::Timeout=120'], null, 600, $env);
        [, $o] = Sys::run(['apt', 'list', '--upgradable'], null, 120, $env);
        $pk = self::parse((string)$o);
        $data = ['checked' => time(), 'error' => $c === 0 ? '' : mb_substr(trim((string)$e), -400), 'packages' => $pk,
            'important' => count(array_filter($pk, static fn($p) => $p['security'] || $p['php'])), 'reboot' => self::reboot()];
        Sys::write(self::CACHE, json_encode($data, JSON_UNESCAPED_SLASHES) . "\n", 0644);
        return $data;
    }

    /** Last check (no network), the running/finished job and whether a restart of the server is needed. */
    public static function status(): array
    {
        $cache = is_file(self::CACHE) ? json_decode((string)file_get_contents(self::CACHE), true) : null;
        $st = is_file(self::STATUS) ? trim((string)file_get_contents(self::STATUS)) : '';
        $active = trim(Sys::run(['systemctl', 'show', self::UNIT, '-p', 'ActiveState', '--value'])[1]);
        $log = is_file(self::LOG) ? (string)file_get_contents(self::LOG, false, null, max(0, filesize(self::LOG) - 20000)) : '';
        return ['cache' => is_array($cache) ? $cache : null, 'state' => in_array($active, ['active', 'activating'], true) ? 'running' : (explode(' ', $st)[0] ?: ''),
            'finished' => explode(' ', $st)[1] ?? '', 'log' => $log, 'reboot' => self::reboot()];
    }

    public static function upgrade(array $a): array
    {
        $mode = ($a['mode'] ?? 'security') === 'all' ? 'all' : 'security';
        $state = trim(Sys::run(['systemctl', 'show', self::UNIT, '-p', 'ActiveState', '--value'])[1]);
        if (in_array($state, ['active', 'activating'], true)) {
            throw new \RuntimeException('Updates are already being installed');
        }
        Sys::run(['systemctl', 'reset-failed', self::UNIT]);
        Sys::ok(['systemd-run', '--no-block', '--unit=' . self::UNIT, '--description=Havoc WEB Panel system updates',
            '--property=TimeoutStartSec=3600', '/opt/havoc/bin/havoc-os-upgrade', $mode]);
        return ['mode' => $mode];
    }

    private static function reboot(): array
    {
        if (!is_file('/var/run/reboot-required')) {
            return ['needed' => false, 'packages' => []];
        }
        $p = is_file('/var/run/reboot-required.pkgs') ? array_values(array_unique(array_filter(array_map('trim', file('/var/run/reboot-required.pkgs') ?: [])))) : [];
        return ['needed' => true, 'packages' => $p];
    }
}
