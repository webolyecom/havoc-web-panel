<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/** Redis (Debian package installed and removed on demand, managed through an include file) and Meilisearch (official release binary, installed on demand). */
final class Stack
{
    private const RCONF = '/etc/redis/redis.conf';
    private const RINC  = '/etc/redis/havoc.conf';
    private const POLICIES = ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'volatile-lru', 'volatile-lfu', 'allkeys-random', 'volatile-random', 'volatile-ttl'];

    private static function state(string $unit): array
    {
        $r = [];
        [, $o] = Sys::run(['systemctl', 'show', $unit, '-p', 'LoadState', '-p', 'ActiveState', '-p', 'UnitFileState']);
        foreach (explode("\n", trim($o)) as $l) {
            [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
            $r[$k] = $v;
        }
        return ['loaded' => ($r['LoadState'] ?? '') === 'loaded', 'active' => $r['ActiveState'] ?? '', 'enabled' => $r['UnitFileState'] ?? ''];
    }

    /** State of a background job unit started with systemd-run, plus its last output while it runs or after it failed. */
    private static function job(string $unit): array
    {
        $st = trim(Sys::run(['systemctl', 'show', $unit, '-p', 'ActiveState', '--value'])[1]);
        $log = in_array($st, ['active', 'activating', 'failed'], true)
            ? trim(Sys::run(['journalctl', '-u', $unit, '--no-pager', '-n', '15', '-o', 'cat'])[1]) : '';
        return [$st, $log];
    }

    private static function startJob(string $unit, array $cmd): void
    {
        [$st] = self::job($unit);
        if (in_array($st, ['active', 'activating'], true)) {
            throw new \RuntimeException('Another operation is already running');
        }
        if ($st === 'failed') {
            Sys::run(['systemctl', 'reset-failed', $unit]);
        }
        Sys::ok(['systemd-run', '--no-block', '--unit=' . $unit, ...$cmd]);
    }

    private static function memMb(): int
    {
        return preg_match('/MemTotal:\s+(\d+)/', (string)file_get_contents('/proc/meminfo'), $m) ? intdiv((int)$m[1], 1024) : 0;
    }

    private const RJOB = 'havoc-redis-job';

    private static function redisInstalled(): bool
    {
        return str_contains(Sys::run(['dpkg-query', '-W', '-f=${Status}', 'redis-server'])[1], 'install ok installed');
    }

    private static function redisPassword(): string
    {
        return is_readable(self::RINC) && preg_match('/^requirepass (\S+)$/m', (string)file_get_contents(self::RINC), $m) ? $m[1] : '';
    }

    public static function redisStatus(): array
    {
        $cfg = ['maxmemory' => '', 'policy' => 'noeviction', 'appendonly' => 'no', 'password' => false];
        foreach (is_file(self::RINC) ? (file(self::RINC, FILE_IGNORE_NEW_LINES) ?: []) : [] as $l) {
            if (preg_match('/^maxmemory (\S+)$/D', $l, $m)) {
                $cfg['maxmemory'] = $m[1];
            } elseif (preg_match('/^maxmemory-policy (\S+)$/D', $l, $m)) {
                $cfg['policy'] = $m[1];
            } elseif (preg_match('/^appendonly (yes|no)$/D', $l, $m)) {
                $cfg['appendonly'] = $m[1];
            } elseif (str_starts_with($l, 'requirepass ')) {
                $cfg['password'] = true;
            }
        }
        [$job, $log] = self::job(self::RJOB);
        $installed = self::redisInstalled();
        $info = [];
        $st = self::state('redis-server');
        if ($installed && $st['active'] === 'active') {
            // INFO through redis-cli; the password goes in the environment, never on the command line
            $env = ($pw = self::redisPassword()) !== '' ? ['REDISCLI_AUTH' => $pw] : [];
            [$c, $o] = Sys::run(['redis-cli', '-h', '127.0.0.1', 'INFO'], null, 10, $env);
            if ($c === 0) {
                foreach (explode("\n", $o) as $l) {
                    if (preg_match('/^(redis_version|used_memory|used_memory_peak|connected_clients|uptime_in_seconds|db\d+):(.+)$/', trim($l), $m)) {
                        $info[$m[1]] = $m[2];
                    }
                }
            }
        }
        $keys = 0;
        foreach ($info as $k => $v) {
            if (str_starts_with($k, 'db') && preg_match('/keys=(\d+)/', $v, $m)) {
                $keys += (int)$m[1];
            }
        }
        return ['installed' => $installed, 'config' => $cfg, 'job' => $job, 'log' => $log,
            'version' => $info['redis_version'] ?? '', 'used_memory' => (int)($info['used_memory'] ?? 0), 'clients' => (int)($info['connected_clients'] ?? 0),
            'keys' => $keys, 'php_ext' => self::phpRedis()] + $st;
    }

    /** PHP versions that have the redis extension available. */
    private static function phpRedis(): array
    {
        $v = [];
        foreach (glob('/etc/php/*/mods-available/redis.ini') ?: [] as $f) {
            $v[] = basename(dirname($f, 2));
        }
        sort($v, SORT_NATURAL);
        return $v;
    }

    public static function redisInstall(): array
    {
        self::startJob(self::RJOB, ['/opt/havoc/bin/havoc-redis-job', 'install']);
        return [];
    }

    /** Removes the packages; configuration and data are deleted only with purge. */
    public static function redisUninstall(array $a): array
    {
        self::startJob(self::RJOB, ['/opt/havoc/bin/havoc-redis-job', !empty($a['purge']) ? 'purge' : 'remove']);
        return [];
    }

    public static function redisKey(): array
    {
        $pw = self::redisPassword();
        if ($pw === '') {
            throw new \RuntimeException('Redis has no password; set one with "generate a new one"');
        }
        return ['key' => $pw];
    }

    public static function redisSet(array $a): array
    {
        $mm = (string)($a['maxmemory'] ?? '');
        if ($mm !== '' && !preg_match('/^[1-9][0-9]{0,5}(mb|gb)$/D', $mm)) {
            throw new \InvalidArgumentException('maxmemory must look like 256mb or 2gb');
        }
        $pol = (string)($a['policy'] ?? 'noeviction');
        if (!in_array($pol, self::POLICIES, true)) {
            throw new \InvalidArgumentException('Invalid eviction policy');
        }
        $aof = !empty($a['appendonly']) ? 'yes' : 'no';
        $old = is_file(self::RINC) ? (string)file_get_contents(self::RINC) : null;
        $pw = null;
        if (array_key_exists('password', $a) && $a['password'] !== null) {
            $pw = (string)$a['password'];
            if ($pw !== '' && !preg_match('/^[A-Za-z0-9]{16,64}$/D', $pw)) {
                throw new \InvalidArgumentException('Password: 16-64 letters/digits');
            }
        } elseif ($old !== null && preg_match('/^requirepass (\S+)$/m', $old, $m)) {
            $pw = $m[1];                                       // keep the current one
        }
        $txt = "# Managed by Havoc WEB Panel\n" . ($mm !== '' ? "maxmemory $mm\n" : '') . "maxmemory-policy $pol\nappendonly $aof\n" . ($pw ? "requirepass $pw\n" : '');
        if (!self::redisInstalled()) {
            throw new \RuntimeException('Redis is not installed');
        }
        Sys::write(self::RINC, $txt, 0640, 'root', 'redis');
        if (is_file(self::RCONF) && !str_contains((string)file_get_contents(self::RCONF), 'include ' . self::RINC)) {
            file_put_contents(self::RCONF, "\ninclude " . self::RINC . "\n", FILE_APPEND);
        }
        if (Sys::run(['systemctl', 'restart', 'redis-server'])[0] !== 0) {
            $old === null ? @unlink(self::RINC) : Sys::write(self::RINC, $old, 0640, 'root', 'redis');
            Sys::run(['systemctl', 'restart', 'redis-server']);
            throw new \RuntimeException('Redis did not start with these settings; previous settings restored');
        }
        return [];
    }

    // ---- Meilisearch ---------------------------------------------------------------------------------------------
    // Installed from the official GitHub release binary (the vendor's apt repo is unsigned: [trusted=yes]).
    // Runs as user "meilisearch", production mode, master key, bound to 127.0.0.1:7700 only.

    private const MBIN  = '/usr/local/bin/meilisearch';
    private const MCONF = '/etc/meilisearch/config.toml';
    private const MUNIT = '/etc/systemd/system/meilisearch.service';
    private const MDATA = '/var/lib/meilisearch';
    private const MJOB  = 'havoc-meili-install';

    private static function meiliConf(): array
    {
        $c = [];
        if (is_file(self::MCONF)) {
            foreach (file(self::MCONF, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
                if (preg_match('/^([a-z_]+)\s*=\s*"?([^"]*)"?\s*$/D', $l, $m)) {
                    $c[$m[1]] = $m[2];
                }
            }
        }
        return $c;
    }

    private static function meiliWriteConf(array $c): void
    {
        $out = "# Managed by Havoc WEB Panel\n";
        foreach ($c as $k => $v) {
            if (!preg_match('/^[a-z_]+$/D', $k) || !preg_match('/^[A-Za-z0-9 .:\/_-]*$/D', (string)$v)) {
                throw new \InvalidArgumentException('Invalid configuration value');
            }
            $out .= is_bool($v) || $v === 'true' || $v === 'false' ? "$k = " . ($v === true || $v === 'true' ? 'true' : 'false') . "\n" : "$k = \"$v\"\n";
        }
        Sys::write(self::MCONF, $out, 0640, 'root', 'meilisearch');
    }

    public static function meiliStatus(): array
    {
        [$job, $log] = self::job(self::MJOB);
        $installed = is_file(self::MBIN) && is_file(self::MUNIT);
        $version = '';
        $health = false;
        $mem = 0;
        $size = 0;
        if ($installed) {
            [, $v] = Sys::run([self::MBIN, '--version'], null, 10);
            $version = preg_match('/(\d+\.\d+\.\d+)/', $v, $m) ? $m[1] : '';
            $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
            $health = str_contains((string)@file_get_contents('http://127.0.0.1:7700/health', false, $ctx), 'available');
            $c = self::meiliConf();
            $mem = preg_match('/^(\d+) MiB$/D', $c['max_indexing_memory'] ?? '', $m) ? (int)$m[1] : 0;
            $size = (int)Sys::run(['du', '-sx', '--block-size=1', self::MDATA], null, 60)[1];
        }
        return ['installed' => $installed, 'version' => $version, 'health' => $health, 'job' => $job, 'log' => $log,
            'mem_mb' => self::memMb(), 'indexing_mb' => $mem, 'data_bytes' => $size] + self::state('meilisearch');
    }

    public static function meiliInstall(): array
    {
        self::startJob(self::MJOB, ['/opt/havoc/bin/havoc-meili-install']);
        return [];
    }

    /** Stops and removes the service, binary and config. Index data is deleted only with purge. */
    public static function meiliUninstall(array $a): array
    {
        Sys::run(['systemctl', 'disable', '--now', 'meilisearch'], null, 60);
        @unlink(self::MUNIT);
        Sys::run(['systemctl', 'daemon-reload']);
        @unlink(self::MBIN);
        Sys::run(['rm', '-rf', '/etc/meilisearch']);
        if (!empty($a['purge'])) {
            Sys::run(['rm', '-rf', self::MDATA]);
            Sys::run(['userdel', 'meilisearch']);
        }
        return [];
    }

    private static function meiliRestart(array $old): void
    {
        Sys::run(['systemctl', 'restart', 'meilisearch'], null, 60);
        for ($i = 0; $i < 20; $i++) {
            usleep(500000);
            if (trim(Sys::run(['systemctl', 'is-active', 'meilisearch'])[1]) === 'active') {
                return;
            }
        }
        self::meiliWriteConf($old);
        Sys::run(['systemctl', 'restart', 'meilisearch'], null, 60);
        throw new \RuntimeException('Meilisearch did not start with these settings; previous settings restored');
    }

    public static function meiliConfigure(array $a): array
    {
        if (!is_file(self::MCONF)) {
            throw new \RuntimeException('Meilisearch is not installed');
        }
        $mb = (int)($a['indexing_mb'] ?? 0);
        if ($mb < 128 || $mb > max(128, intdiv(self::memMb(), 2))) {
            throw new \InvalidArgumentException('Indexing memory must be 128 MB or more and at most half of the RAM');
        }
        $old = self::meiliConf();
        $new = $old;
        $new['max_indexing_memory'] = "$mb MiB";
        self::meiliWriteConf($new);
        self::meiliRestart($old);
        return [];
    }

    public static function meiliKey(array $a): array
    {
        $c = self::meiliConf();
        if (!isset($c['master_key'])) {
            throw new \RuntimeException('Meilisearch is not installed');
        }
        if (empty($a['rotate'])) {
            return ['key' => $c['master_key']];
        }
        $old = $c;
        $c['master_key'] = bin2hex(random_bytes(24));
        self::meiliWriteConf($c);
        self::meiliRestart($old);
        return ['key' => $c['master_key']];
    }
}
