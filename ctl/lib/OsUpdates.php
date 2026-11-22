<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Operating system updates (Monitoring → System updates).
 * - check(): `apt-get update` and the list of upgradable packages, saved to CACHE (read cheaply by the panel).
 *   "security": from a Debian security suite; "php": PHP packages (sury.org publishes PHP security releases in its normal
 *   suite, so every PHP update counts as important).
 * - software(): the web servers, MariaDB, PHP versions and add-ons with installed / available version (apt packages, or
 *   LiteSpeed Enterprise via lsup.sh, Meilisearch from GitHub, phpMyAdmin from phpmyadmin.net).
 * - upgrade(): starts bin/havoc-os-upgrade in its own unit (security = security + PHP packages only, all = apt-get upgrade,
 *   component = one piece of software);
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
            'important' => count(array_filter($pk, static fn($p) => $p['security'] || $p['php'])), 'reboot' => self::reboot(),
            'software' => self::software($pk)];
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

    /**
     * Software the panel installs, with installed and available version.
     * apt components: [label, main package, regex of the packages updated together]; the others update themselves.
     */
    private const APT = [
        'nginx' => ['Nginx', 'nginx', '/^(nginx|nginx-[a-z0-9-]+|libnginx-mod-[a-z0-9-]+)$/'],
        'ols' => ['OpenLiteSpeed', 'openlitespeed', '/^openlitespeed$/'],
        'mariadb' => ['MariaDB', 'mariadb-server', '/^(mariadb-[a-z0-9.-]+|libmariadb[a-z0-9.-]*|galera-[0-9]+)$/'],
        'redis' => ['Redis', 'redis-server', '/^(redis-server|redis-tools)$/'],
        'memcached' => ['Memcached', 'memcached', '/^memcached$/'],
        'fail2ban' => ['Fail2ban', 'fail2ban', '/^fail2ban$/'],
        'nodejs' => ['Node.js', 'nodejs', '/^nodejs$/'],
    ];

    public static function software(array $pk): array
    {
        $up = array_column($pk, null, 'name');
        $installed = [];
        [, $o] = Sys::run(['dpkg-query', '-W', '-f', '${Package} ${Version} ${db:Status-Abbrev}\n'], null, 60);
        foreach (explode("\n", (string)$o) as $l) {
            $x = preg_split('/\s+/', trim($l));
            if (count($x) >= 3 && str_starts_with($x[2], 'ii')) {
                $installed[$x[0]] = $x[1];
            }
        }
        $apt = self::APT;
        foreach (glob('/usr/sbin/php-fpm8.*') ?: [] as $f) {               // one row per installed PHP version
            $v = substr(basename($f), 7);
            if (preg_match('/^8\.\d+$/', $v)) {
                $apt['php' . $v] = ['PHP ' . $v, "php$v-fpm", '/^php' . preg_quote($v, '/') . '-[a-z0-9-]+$/'];
            }
        }
        $out = [];
        foreach ($apt as $k => [$label, $main, $rx]) {
            if (!isset($installed[$main])) {
                continue;
            }
            $pkgs = array_values(array_filter(array_keys($up), static fn($n) => preg_match($rx, $n)));
            $out[$k] = ['label' => $label, 'source' => 'apt', 'installed' => $installed[$main], 'available' => $up[$main]['to'] ?? ($pkgs ? $up[$pkgs[0]]['to'] : ''),
                'packages' => $pkgs, 'security' => (bool)array_filter($pkgs, static fn($n) => $up[$n]['security'] || $up[$n]['php'])];
        }
        $get = static function (string $url): string {
            [$c, $o] = Sys::run(['curl', '-fsSL', '--proto', '=https', '--tlsv1.2', '--max-time', '15', '-H', 'User-Agent: Havoc-WEB-Panel', $url], null, 30);
            return $c === 0 ? (string)$o : '';
        };
        if (is_file('/usr/local/lsws/bin/lshttpd') && !is_file('/usr/local/lsws/bin/openlitespeed')) {
            $cur = trim((string)@file_get_contents('/usr/local/lsws/VERSION'));
            $latest = preg_match('/LSWS=([0-9.]+)/', $get('https://update.litespeedtech.com/ws/latest.php'), $m) ? $m[1] : '';
            $out['lsws'] = ['label' => 'LiteSpeed Enterprise', 'source' => 'lsup', 'installed' => $cur, 'latest' => $latest, 'available' => self::newer($cur, $latest), 'packages' => [], 'security' => false];
        }
        if (is_file('/usr/local/bin/meilisearch')) {
            $cur = preg_match('/([0-9]+\.[0-9]+\.[0-9]+)/', (string)Sys::run(['/usr/local/bin/meilisearch', '--version'], null, 20)[1], $m) ? $m[1] : '';
            $j = json_decode($get('https://api.github.com/repos/meilisearch/meilisearch/releases/latest'), true);
            $latest = ltrim((string)($j['tag_name'] ?? ''), 'v');
            $out['meilisearch'] = ['label' => 'Meilisearch', 'source' => 'github', 'installed' => $cur, 'latest' => $latest, 'available' => self::newer($cur, $latest), 'packages' => [], 'security' => false];
        }
        if (is_file('/opt/havoc-pma/index.php')) {
            $cur = '';
            foreach (glob('/opt/havoc-pma/RELEASE-DATE-*') ?: [] as $f) {
                $cur = substr(basename($f), 13);
            }
            if ($cur === '' && preg_match('/"version"\s*:\s*"([0-9.]+)"/', (string)@file_get_contents('/opt/havoc-pma/package.json'), $m)) {
                $cur = $m[1];
            }
            $latest = trim(strtok($get('https://www.phpmyadmin.net/home_page/version.txt'), "\n") ?: '');
            $out['phpmyadmin'] = ['label' => 'phpMyAdmin', 'source' => 'phpmyadmin.net', 'installed' => $cur, 'latest' => preg_match('/^[0-9.]+$/', $latest) ? $latest : '',
                'available' => self::newer($cur, $latest), 'packages' => [], 'security' => false];
        }
        return $out;
    }

    /** $latest when it is a newer version than $cur, else '' */
    private static function newer(string $cur, string $latest): string
    {
        return preg_match('/^[0-9]+(\.[0-9]+){1,3}$/', $latest) && ($cur === '' || version_compare($latest, $cur, '>')) ? $latest : '';
    }

    public static function upgrade(array $a): array
    {
        $mode = in_array($a['mode'] ?? '', ['all', 'component'], true) ? $a['mode'] : 'security';
        $args = [$mode];
        if ($mode === 'component') {
            $k = (string)($a['component'] ?? '');
            $cache = is_file(self::CACHE) ? json_decode((string)file_get_contents(self::CACHE), true) : [];
            $sw = $cache['software'][$k] ?? throw new \InvalidArgumentException('Unknown software');
            if ($sw['source'] === 'apt') {
                if (!$sw['packages']) {
                    throw new \RuntimeException('No update is waiting for ' . $sw['label']);
                }
                $args = ['packages', ...array_map(static fn($n) => preg_match('/^[a-z0-9][a-z0-9+.-]*$/D', $n) ? $n : throw new \InvalidArgumentException('Bad package'), $sw['packages'])];
            } else {
                $args = [match ($k) { 'lsws' => 'lsws', 'meilisearch' => 'meilisearch', 'phpmyadmin' => 'phpmyadmin' }];
            }
        }
        $mode = $args[0];
        $state = trim(Sys::run(['systemctl', 'show', self::UNIT, '-p', 'ActiveState', '--value'])[1]);
        if (in_array($state, ['active', 'activating'], true)) {
            throw new \RuntimeException('Updates are already being installed');
        }
        Sys::run(['systemctl', 'reset-failed', self::UNIT]);
        Sys::ok(['systemd-run', '--no-block', '--unit=' . self::UNIT, '--description=Havoc WEB Panel system updates',
            '--property=TimeoutStartSec=3600', '/opt/havoc/bin/havoc-os-upgrade', ...$args]);
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
