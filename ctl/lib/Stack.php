<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/** Redis (Debian package, managed through an include file) and Meilisearch (official release binary, installed on demand). */
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

    private static function memMb(): int
    {
        return preg_match('/MemTotal:\s+(\d+)/', (string)file_get_contents('/proc/meminfo'), $m) ? intdiv((int)$m[1], 1024) : 0;
    }

    public static function redisStatus(): array
    {
        $cfg = ['maxmemory' => '', 'policy' => 'noeviction', 'appendonly' => 'no', 'password' => false];
        foreach (is_file(self::RINC) ? (file(self::RINC, FILE_IGNORE_NEW_LINES) ?: []) : [] as $l) {
            if (preg_match('/^maxmemory (\S+)$/', $l, $m)) {
                $cfg['maxmemory'] = $m[1];
            } elseif (preg_match('/^maxmemory-policy (\S+)$/', $l, $m)) {
                $cfg['policy'] = $m[1];
            } elseif (preg_match('/^appendonly (yes|no)$/', $l, $m)) {
                $cfg['appendonly'] = $m[1];
            } elseif (str_starts_with($l, 'requirepass ')) {
                $cfg['password'] = true;
            }
        }
        return ['installed' => is_file('/usr/bin/redis-server'), 'config' => $cfg] + self::state('redis-server');
    }

    public static function redisSet(array $a): array
    {
        $mm = (string)($a['maxmemory'] ?? '');
        if ($mm !== '' && !preg_match('/^[1-9][0-9]{0,5}(mb|gb)$/', $mm)) {
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
            if ($pw !== '' && !preg_match('/^[A-Za-z0-9]{16,64}$/', $pw)) {
                throw new \InvalidArgumentException('Password: 16-64 letters/digits');
            }
        } elseif ($old !== null && preg_match('/^requirepass (\S+)$/m', $old, $m)) {
            $pw = $m[1];                                       // keep the current one
        }
        $txt = "# Managed by Havoc WEB Panel\n" . ($mm !== '' ? "maxmemory $mm\n" : '') . "maxmemory-policy $pol\nappendonly $aof\n" . ($pw ? "requirepass $pw\n" : '');
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
                if (preg_match('/^([a-z_]+)\s*=\s*"?([^"]*)"?\s*$/', $l, $m)) {
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
            if (!preg_match('/^[a-z_]+$/', $k) || !preg_match('/^[A-Za-z0-9 .:\/_-]*$/', (string)$v)) {
                throw new \InvalidArgumentException('Invalid configuration value');
            }
            $out .= is_bool($v) || $v === 'true' || $v === 'false' ? "$k = " . ($v === true || $v === 'true' ? 'true' : 'false') . "\n" : "$k = \"$v\"\n";
        }
        Sys::write(self::MCONF, $out, 0640, 'root', 'meilisearch');
    }

    public static function meiliStatus(): array
    {
        $job = trim(Sys::run(['systemctl', 'show', self::MJOB, '-p', 'ActiveState', '--value'])[1]);
        $log = in_array($job, ['active', 'activating', 'failed'], true)
            ? trim(Sys::run(['journalctl', '-u', self::MJOB, '--no-pager', '-n', '15', '-o', 'cat'])[1]) : '';
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
            $mem = preg_match('/^(\d+) MiB$/', $c['max_indexing_memory'] ?? '', $m) ? (int)$m[1] : 0;
            $size = (int)Sys::run(['du', '-sx', '--block-size=1', self::MDATA], null, 60)[1];
        }
        return ['installed' => $installed, 'version' => $version, 'health' => $health, 'job' => $job, 'log' => $log,
            'mem_mb' => self::memMb(), 'indexing_mb' => $mem, 'data_bytes' => $size] + self::state('meilisearch');
    }

    public static function meiliInstall(): array
    {
        $job = trim(Sys::run(['systemctl', 'show', self::MJOB, '-p', 'ActiveState', '--value'])[1]);
        if (in_array($job, ['active', 'activating'], true)) {
            throw new \RuntimeException('Installation is already running');
        }
        if ($job === 'failed') {
            Sys::run(['systemctl', 'reset-failed', self::MJOB]);
        }
        Sys::ok(['systemd-run', '--no-block', '--unit=' . self::MJOB, '/opt/havoc/bin/havoc-meili-install']);
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
