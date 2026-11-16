<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Optional add-ons installed on demand: Memcached, Fail2ban and Node.js (Redis and Meilisearch live in Stack).
 * Installing and removing runs in the background (bin/havoc-addon-job through systemd-run); settings are applied here.
 */
final class Addons
{
    public const NAMES = ['memcached', 'fail2ban', 'nodejs'];
    public const NODE_MAJORS = ['22', '24', '26'];
    public const NODE_GLOBALS = ['pm2', 'yarn', 'pnpm'];
    private const JOB = '/opt/havoc/bin/havoc-addon-job';

    private static function name(array $a): string
    {
        $n = (string)($a['name'] ?? '');
        if (!in_array($n, self::NAMES, true)) {
            throw new \InvalidArgumentException('Unknown add-on');
        }
        return $n;
    }

    private static function unit(string $name): string
    {
        return 'havoc-addon-' . $name;
    }

    private static function pkgInstalled(string $pkg): bool
    {
        return str_contains(Sys::run(['dpkg-query', '-W', '-f=${Status}', $pkg])[1], 'install ok installed');
    }

    private static function phpExt(string $ext): array
    {
        $v = [];
        foreach (glob("/etc/php/*/mods-available/$ext.ini") ?: [] as $f) {
            $v[] = basename(dirname($f, 2));
        }
        sort($v, SORT_NATURAL);
        return $v;
    }

    public static function status(array $a): array
    {
        $n = self::name($a);
        [$job, $log] = Stack::job(self::unit($n));
        $base = ['name' => $n, 'job' => $job, 'log' => $log];
        return $base + match ($n) {
            'memcached' => self::memcachedStatus(),
            'fail2ban'  => self::f2bStatus(),
            'nodejs'    => self::nodeStatus(),
        };
    }

    /** Overview: installed / running for every add-on, cheap (no statistics). */
    public static function overview(): array
    {
        $out = [];
        foreach (['redis' => ['redis-server', 'redis-server', 'havoc-redis-job'], 'meilisearch' => [null, 'meilisearch', 'havoc-meili-install'],
                  'memcached' => ['memcached', 'memcached', 'havoc-addon-memcached'], 'fail2ban' => ['fail2ban', 'fail2ban', 'havoc-addon-fail2ban'],
                  'nodejs' => ['nodejs', null, 'havoc-addon-nodejs']] as $k => [$pkg, $svc, $unit]) {
            $installed = $pkg ? self::pkgInstalled($pkg) : (is_file('/usr/local/bin/meilisearch') && is_file('/etc/systemd/system/meilisearch.service'));
            [$job] = Stack::job($unit);
            $active = $svc && $installed ? Stack::state($svc)['active'] : '';
            $version = '';
            if ($k === 'nodejs' && $installed) {
                $version = ltrim(trim(Sys::run(['node', '-v'], null, 10)[1]), 'v');
            }
            $out[$k] = ['installed' => $installed, 'active' => $active, 'busy' => in_array($job, ['active', 'activating'], true), 'failed' => $job === 'failed', 'version' => $version];
        }
        return ['addons' => $out];
    }

    public static function install(array $a): array
    {
        $n = self::name($a);
        $cmd = [self::JOB, $n, 'install'];
        if ($n === 'nodejs') {
            $major = (string)($a['version'] ?? '24');
            if (!in_array($major, self::NODE_MAJORS, true)) {
                throw new \InvalidArgumentException('Unsupported Node.js version');
            }
            $cmd = [...$cmd, $major, ...array_values(array_intersect(self::NODE_GLOBALS, (array)($a['globals'] ?? [])))];
        }
        Stack::startJob(self::unit($n), $cmd);
        return [];
    }

    public static function uninstall(array $a): array
    {
        $n = self::name($a);
        Stack::startJob(self::unit($n), [self::JOB, $n, !empty($a['purge']) ? 'purge' : 'remove']);
        return [];
    }

    // ---- Memcached -----------------------------------------------------------------------------------------------

    private const MCONF = '/etc/memcached.conf';

    private static function memcachedStatus(): array
    {
        $installed = self::pkgInstalled('memcached');
        $cfg = ['memory' => 64, 'conns' => 1024];
        foreach (is_file(self::MCONF) ? (file(self::MCONF, FILE_IGNORE_NEW_LINES) ?: []) : [] as $l) {
            if (preg_match('/^-m\s+(\d+)/', $l, $m)) {
                $cfg['memory'] = (int)$m[1];
            } elseif (preg_match('/^-c\s+(\d+)/', $l, $m)) {
                $cfg['conns'] = (int)$m[1];
            }
        }
        $st = Stack::state('memcached');
        $stats = [];
        if ($installed && $st['active'] === 'active' && ($s = @stream_socket_client('tcp://127.0.0.1:11211', $en, $es, 2))) {
            stream_set_timeout($s, 2);
            fwrite($s, "stats\r\n");
            while (($l = fgets($s)) !== false && trim($l) !== 'END') {
                if (preg_match('/^STAT (\w+) (\S+)/', $l, $m)) {
                    $stats[$m[1]] = $m[2];
                }
            }
            fclose($s);
        }
        $hits = (int)($stats['get_hits'] ?? 0);
        $miss = (int)($stats['get_misses'] ?? 0);
        return ['installed' => $installed, 'config' => $cfg, 'mem_mb' => Stack::memMb(), 'php_ext' => self::phpExt('memcached'),
            'version' => $stats['version'] ?? '', 'items' => (int)($stats['curr_items'] ?? 0), 'bytes' => (int)($stats['bytes'] ?? 0),
            'limit' => (int)($stats['limit_maxbytes'] ?? 0), 'clients' => (int)($stats['curr_connections'] ?? 0),
            'hit_rate' => $hits + $miss ? round(100 * $hits / ($hits + $miss), 1) : null, 'evictions' => (int)($stats['evictions'] ?? 0)] + $st;
    }

    public static function memcachedSet(array $a): array
    {
        if (!self::pkgInstalled('memcached') || !is_file(self::MCONF)) {
            throw new \RuntimeException('Memcached is not installed');
        }
        $mem = (int)($a['memory'] ?? 0);
        $conns = (int)($a['conns'] ?? 0);
        if ($mem < 16 || $mem > max(16, intdiv(Stack::memMb(), 2))) {
            throw new \InvalidArgumentException('Memory must be between 16 MB and half of the RAM');
        }
        if ($conns < 64 || $conns > 65535) {
            throw new \InvalidArgumentException('Connections must be between 64 and 65535');
        }
        $old = (string)file_get_contents(self::MCONF);
        $new = preg_replace('/^-m\s+\d+.*$/m', "-m $mem", $old);
        $new = preg_match('/^-c\s+\d+/m', $new) ? preg_replace('/^-c\s+\d+.*$/m', "-c $conns", $new) : rtrim($new) . "\n-c $conns\n";
        if (!preg_match('/^-m /m', $new)) {
            $new = rtrim($new) . "\n-m $mem\n";
        }
        Sys::write(self::MCONF, $new, 0644);
        if (Sys::run(['systemctl', 'restart', 'memcached'], null, 60)[0] !== 0) {
            Sys::write(self::MCONF, $old, 0644);
            Sys::run(['systemctl', 'restart', 'memcached'], null, 60);
            throw new \RuntimeException('Memcached did not start with these settings; previous settings restored');
        }
        return [];
    }

    public static function memcachedFlush(): array
    {
        $s = @stream_socket_client('tcp://127.0.0.1:11211', $en, $es, 2);
        if (!$s) {
            throw new \RuntimeException('Memcached is not running');
        }
        fwrite($s, "flush_all\r\n");
        $r = trim((string)fgets($s));
        fclose($s);
        if ($r !== 'OK') {
            throw new \RuntimeException('Memcached answered: ' . mb_substr($r, 0, 80));
        }
        return [];
    }

    // ---- Fail2ban ------------------------------------------------------------------------------------------------

    private const F2B_JAIL   = '/etc/fail2ban/jail.d/havoc.conf';
    private const F2B_FILTER = '/etc/fail2ban/filter.d/havoc-panel.conf';
    public const F2B_JAILS   = ['sshd', 'pure-ftpd', 'havoc-panel', 'recidive'];

    /** Settings as written in the managed jail file (the file is the only source of truth). */
    private static function f2bConfig(): array
    {
        $c = ['bantime' => 60, 'findtime' => 10, 'maxretry' => 5, 'ignoreip' => [], 'jails' => ['sshd' => true, 'pure-ftpd' => true, 'havoc-panel' => true, 'recidive' => false]];
        if (!is_file(self::F2B_JAIL)) {
            return $c;
        }
        $sect = '';
        foreach (file(self::F2B_JAIL, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\[([A-Za-z-]+)\]$/', trim($l), $m)) {
                $sect = $m[1];
            } elseif (preg_match('/^(\w+)\s*=\s*(.*)$/', trim($l), $m)) {
                if ($sect === 'DEFAULT' && in_array($m[1], ['bantime', 'findtime'], true) && preg_match('/^(\d+)m$/', $m[2], $v)) {
                    $c[$m[1]] = (int)$v[1];
                } elseif ($sect === 'DEFAULT' && $m[1] === 'maxretry') {
                    $c['maxretry'] = (int)$m[2];
                } elseif ($sect === 'DEFAULT' && $m[1] === 'ignoreip') {
                    $c['ignoreip'] = array_values(array_diff(preg_split('/\s+/', trim($m[2])) ?: [], ['127.0.0.1/8', '::1', '']));
                } elseif (isset($c['jails'][$sect]) && $m[1] === 'enabled') {
                    $c['jails'][$sect] = $m[2] === 'true';
                }
            }
        }
        return $c;
    }

    private static function f2bStatus(): array
    {
        $installed = self::pkgInstalled('fail2ban');
        $st = Stack::state('fail2ban');
        $jails = [];
        $version = '';
        if ($installed && $st['active'] === 'active') {
            $version = preg_match('/(\d+\.\d+\.\d+)/', Sys::run(['fail2ban-client', '--version'], null, 10)[1], $m) ? $m[1] : '';
            [, $o] = Sys::run(['fail2ban-client', 'status'], null, 15);
            $names = preg_match('/Jail list:\s*(.*)$/m', $o, $m) ? array_filter(array_map('trim', explode(',', $m[1]))) : [];
            foreach ($names as $j) {
                if (!preg_match('/^[a-z0-9_-]{1,40}$/D', $j)) {
                    continue;
                }
                [, $js] = Sys::run(['fail2ban-client', 'status', $j], null, 15);
                $jails[$j] = [
                    'failed' => preg_match('/Currently failed:\s*(\d+)/', $js, $m) ? (int)$m[1] : 0,
                    'banned' => preg_match('/Currently banned:\s*(\d+)/', $js, $m) ? (int)$m[1] : 0,
                    'total'  => preg_match('/Total banned:\s*(\d+)/', $js, $m) ? (int)$m[1] : 0,
                    'ips'    => preg_match('/Banned IP list:\s*(.*)$/m', $js, $m) ? array_slice(array_values(array_filter(preg_split('/\s+/', trim($m[1])) ?: [], static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false)), 0, 500) : [],
                ];
            }
        }
        return ['installed' => $installed, 'version' => $version, 'config' => self::f2bConfig(), 'jails' => $jails,
            'ftp' => self::pkgInstalled('pure-ftpd'), 'panel_port' => PanelHost::panelPort()] + $st;
    }

    private static function sshPorts(): string
    {
        if (!is_executable('/usr/sbin/sshd')) {
            return '22';
        }
        [$c, $o] = Sys::run(['/usr/sbin/sshd', '-T'], null, 10);
        $p = $c === 0 && preg_match_all('/^port (\d+)$/m', $o, $m) ? array_unique($m[1]) : ['22'];
        return implode(',', $p);
    }

    private static function cidr(string $s): bool
    {
        [$ip, $len] = array_pad(explode('/', $s, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        return $len === null || (ctype_digit($len) && (int)$len >= 8 && (int)$len <= $max);
    }

    /** Writes the managed jail file and the panel filter, then reloads Fail2ban (restores the old file on failure). */
    public static function f2bApply(array $a): array
    {
        $c = self::f2bConfig();
        if (empty($a['initial'])) {
            foreach (['bantime' => [1, 525600], 'findtime' => [1, 10080], 'maxretry' => [1, 100]] as $k => [$lo, $hi]) {
                $v = (int)($a[$k] ?? 0);
                if ($v < $lo || $v > $hi) {
                    throw new \InvalidArgumentException("$k must be between $lo and $hi");
                }
                $c[$k] = $v;
            }
            $ips = array_values(array_unique(array_filter(array_map('trim', (array)($a['ignoreip'] ?? [])), 'strlen')));
            if (count($ips) > 100) {
                throw new \InvalidArgumentException('At most 100 whitelist entries');
            }
            foreach ($ips as $ip) {
                if (!self::cidr($ip)) {
                    throw new \InvalidArgumentException('Not an IP address or network: ' . mb_substr($ip, 0, 60));
                }
            }
            $c['ignoreip'] = $ips;
            foreach (self::F2B_JAILS as $j) {
                $c['jails'][$j] = !empty($a['jails'][$j]);
            }
        }
        $nft = is_file('/usr/sbin/nft');
        $panelPorts = implode(',', array_unique(array_filter([(string)PanelHost::panelPort(), '443'])));
        $ftp = self::pkgInstalled('pure-ftpd');
        $on = static fn(string $j, bool $avail = true) => $c['jails'][$j] && $avail ? 'true' : 'false';
        $conf = "# Managed by Havoc WEB Panel - change these settings in the panel (Add-ons > Fail2ban)\n"
            . "[DEFAULT]\n"
            . "bantime = {$c['bantime']}m\nfindtime = {$c['findtime']}m\nmaxretry = {$c['maxretry']}\n"
            . 'ignoreip = ' . trim('127.0.0.1/8 ::1 ' . implode(' ', $c['ignoreip'])) . "\n"
            . "backend = systemd\n"
            . ($nft ? "banaction = nftables\nbanaction_allports = nftables[type=allports]\n" : "banaction = iptables-multiport\nbanaction_allports = iptables-allports\n")
            . "\n[sshd]\nenabled = " . $on('sshd') . "\nport = " . self::sshPorts() . "\nbackend = systemd\n"
            . "\n[pure-ftpd]\nenabled = " . $on('pure-ftpd', $ftp) . "\nport = ftp,ftp-data,ftps,ftps-data\nbackend = systemd\n"
            . "\n[havoc-panel]\nenabled = " . $on('havoc-panel') . "\nfilter = havoc-panel\nport = $panelPorts\nbackend = systemd\n"
            . "\n[recidive]\nenabled = " . $on('recidive') . "\nlogpath = /var/log/fail2ban.log\nbackend = auto\nbanaction = %(banaction_allports)s\nbantime = 1w\nfindtime = 1d\nmaxretry = 5\n";
        $filter = "# Managed by Havoc WEB Panel: failed panel logins (written to the journal by the panel)\n"
            . "[INCLUDES]\nbefore = common.conf\n\n[Definition]\n_daemon = havoc-panel\n"
            . "failregex = ^%(__prefix_line)sPanel login failed from <HOST> \\(user: [^)]*\\)\\s*$\n"
            . "ignoreregex =\njournalmatch = SYSLOG_IDENTIFIER=havoc-panel\n";
        $old = is_file(self::F2B_JAIL) ? (string)file_get_contents(self::F2B_JAIL) : null;
        Sys::write(self::F2B_FILTER, $filter, 0644);
        Sys::write(self::F2B_JAIL, $conf, 0644);
        if (!empty($a['initial'])) {
            return [];                                            // the install job starts the service itself
        }
        [$code, , $err] = Sys::run(['fail2ban-client', '-t'], null, 60);
        if ($code !== 0) {
            $old === null ? @unlink(self::F2B_JAIL) : Sys::write(self::F2B_JAIL, $old, 0644);
            throw new \RuntimeException('Fail2ban rejected the settings: ' . mb_substr(trim($err), 0, 300));
        }
        if (Stack::state('fail2ban')['active'] === 'active') {
            Sys::run(['fail2ban-client', 'reload'], null, 60);
        }
        return [];
    }

    public static function f2bBan(array $a): array
    {
        $ip = (string)($a['ip'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Invalid IP address');
        }
        if (!empty($a['unban'])) {
            Sys::ok(['fail2ban-client', 'unban', $ip], null, 30);
            return ['ip' => $ip];
        }
        $jail = (string)($a['jail'] ?? '');
        if (!in_array($jail, self::F2B_JAILS, true)) {
            throw new \InvalidArgumentException('Unknown jail');
        }
        foreach (self::f2bConfig()['ignoreip'] as $w) {
            if ($w === $ip) {
                throw new \InvalidArgumentException('This address is on the whitelist');
            }
        }
        Sys::ok(['fail2ban-client', 'set', $jail, 'banip', $ip], null, 30);
        return ['ip' => $ip];
    }

    // ---- Node.js -------------------------------------------------------------------------------------------------

    private static function nodeStatus(): array
    {
        $installed = self::pkgInstalled('nodejs');
        $node = $npm = '';
        $globals = [];
        if ($installed) {
            $node = ltrim(trim(Sys::run(['node', '-v'], null, 10)[1]), 'v');
            $npm = trim(Sys::run(['npm', '-v'], null, 20)[1]);
            foreach (self::NODE_GLOBALS as $g) {
                $pj = "/usr/lib/node_modules/$g/package.json";
                if (is_file($pj)) {
                    $globals[$g] = (string)(json_decode((string)file_get_contents($pj), true)['version'] ?? '');
                }
            }
        }
        $major = trim((string)@file_get_contents('/etc/havoc/nodejs-major'));
        return ['installed' => $installed, 'node' => $node, 'npm' => preg_match('/^[\d.]+$/', $npm) ? $npm : '',
            'major' => $major !== '' ? $major : ($node !== '' ? explode('.', $node)[0] : ''),
            'nodesource' => is_file('/etc/apt/sources.list.d/nodesource.list'), 'globals' => $globals,
            'majors' => self::NODE_MAJORS, 'available_globals' => self::NODE_GLOBALS];
    }

    public static function nodeGlobals(array $a): array
    {
        if (!self::pkgInstalled('nodejs')) {
            throw new \RuntimeException('Node.js is not installed');
        }
        Stack::startJob(self::unit('nodejs'), [self::JOB, 'nodejs', 'globals', ...array_values(array_intersect(self::NODE_GLOBALS, (array)($a['globals'] ?? [])))]);
        return [];
    }
}
