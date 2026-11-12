<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Account backups: tar.gz with db/*.sql (first) and domains/ . Archives live in /var/backups/havoc/<account>/
 * (root:<account> 0750, files 0640: the tenant can read his own archives but never alter or delete them).
 * Everything that interprets archive content (tar extract, SQL import) runs as the tenant, never as root.
 */
final class Backup
{
    public const DIR = '/var/backups/havoc';

    private static function bin(string ...$c): string
    {
        foreach ($c as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        throw new \RuntimeException('Required tool missing: ' . basename($c[0]));
    }

    private static function account(string $acct): array
    {
        $acct = Validator::account($acct);
        $pw = posix_getpwnam($acct);
        if (!$pw || !is_dir("/home/$acct")) {
            throw new \RuntimeException('Nothing to back up: hosting account does not exist yet');
        }
        return [$acct, $pw];
    }

    private static function file(string $acct, mixed $f): string
    {
        if (!is_string($f) || !preg_match('/^' . $acct . '-[0-9]{8}-[0-9]{6}\.tar\.gz$/', $f)) {
            throw new \InvalidArgumentException('Invalid backup file name');
        }
        return $f;
    }

    private static function asUser(array $pw): array
    {
        return ['setpriv', '--reuid', (string)$pw['uid'], '--regid', (string)$pw['gid'], '--clear-groups', '--no-new-privs'];
    }

    private static function sql(string $sql): void
    {
        Sys::ok([self::bin('/usr/bin/mariadb', '/usr/bin/mysql'), '--batch'], $sql);
    }

    private static function tmp(?array $pw = null): string
    {
        $w = '/var/tmp/havoc-bk-' . bin2hex(random_bytes(6));
        mkdir($w . '/db', 0700, true);
        if ($pw) {
            chown($w, (int)$pw['uid']);
            chown($w . '/db', (int)$pw['uid']);
        }
        return $w;
    }

    private static function cleanup(string $w): void
    {
        if (str_starts_with($w, '/var/tmp/havoc-bk-')) {
            Sys::run(['rm', '-rf', $w]);
        }
    }

    // ---- remote targets (rclone, configured purely through environment variables) -----------------------------

    /** @return array{env:array,base:string} */
    public static function target(array $t): array
    {
        $type = $t['type'] ?? '';
        $path = (string)($t['path'] ?? '');
        if (!preg_match('#^[A-Za-z0-9._\-/]{0,200}$#', $path) || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid remote path');
        }
        $env = [];
        if ($type === 'sftp' || $type === 'ftp') {
            $host = (string)($t['host'] ?? '');
            self::checkHost($host);
            $port = (int)(($t['port'] ?? 0) ?: ($type === 'sftp' ? 22 : 21));
            $user = (string)($t['user'] ?? '');
            $pass = (string)($t['password'] ?? '');
            if ($port < 1 || $port > 65535 || !preg_match('/^[A-Za-z0-9._@\-]{1,64}$/', $user) || $pass === '' || strlen($pass) > 200 || str_contains($pass, "\0")) {
                throw new \InvalidArgumentException('Invalid connection settings');
            }
            $obscured = trim(Sys::ok(['rclone', 'obscure', '-'], $pass));      // secret via stdin, never argv
            $env = ['RCLONE_CONFIG_HV_TYPE' => $type, 'RCLONE_CONFIG_HV_HOST' => $host, 'RCLONE_CONFIG_HV_PORT' => (string)$port,
                    'RCLONE_CONFIG_HV_USER' => $user, 'RCLONE_CONFIG_HV_PASS' => $obscured];
            if ($type === 'ftp' && !empty($t['tls'])) {
                $env['RCLONE_CONFIG_HV_EXPLICIT_TLS'] = 'true';
            }
            return ['env' => $env, 'base' => 'HV:' . $path];
        }
        if ($type === 's3') {
            $bucket = (string)($t['bucket'] ?? '');
            $ak = (string)($t['access_key'] ?? '');
            $sk = (string)($t['secret_key'] ?? '');
            $region = (string)($t['region'] ?? '') ?: 'us-east-1';
            $endpoint = (string)($t['endpoint'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9.\-]{2,62}$/', $bucket) || !preg_match('/^[A-Za-z0-9+\/=_\-]{8,128}$/', $ak) || !preg_match('/^[A-Za-z0-9+\/=_\-]{16,128}$/', $sk)
                || !preg_match('/^[a-z0-9\-]{2,30}$/', $region)) {
                throw new \InvalidArgumentException('Invalid S3 settings');
            }
            $env = ['RCLONE_CONFIG_HV_TYPE' => 's3', 'RCLONE_CONFIG_HV_PROVIDER' => $endpoint ? 'Other' : 'AWS', 'RCLONE_CONFIG_HV_ACCESS_KEY_ID' => $ak,
                    'RCLONE_CONFIG_HV_SECRET_ACCESS_KEY' => $sk, 'RCLONE_CONFIG_HV_REGION' => $region];
            if ($endpoint !== '') {
                if (!preg_match('#^https://([A-Za-z0-9.\-]+)(:[0-9]{2,5})?$#', $endpoint, $m)) {
                    throw new \InvalidArgumentException('Endpoint must be https://host[:port]');
                }
                self::checkHost($m[1]);
                $env['RCLONE_CONFIG_HV_ENDPOINT'] = $endpoint;
            }
            return ['env' => $env, 'base' => 'HV:' . $bucket . ($path !== '' ? '/' . trim($path, '/') : '')];
        }
        throw new \InvalidArgumentException('Unknown target type');
    }

    /** SSRF guard: no loopback / link-local / reserved addresses, no IPv6 literals. */
    private static function checkHost(string $h): void
    {
        if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?$/', $h)) {
            throw new \InvalidArgumentException('Invalid host');
        }
        $ips = filter_var($h, FILTER_VALIDATE_IP) ? [$h] : (gethostbynamel($h) ?: []);
        if (!$ips) {
            throw new \InvalidArgumentException('Host does not resolve');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('Host address is not allowed');
            }
        }
    }

    private static function rclone(array $t, array $args, int $timeout = 7200): string
    {
        $r = self::target($t);
        return Sys::ok(['rclone', '--config', '/dev/null', '--contimeout', '20s', '--low-level-retries', '2', ...array_map(fn($a) => $a === '@BASE' ? $r['base'] : $a, $args)], null, $timeout, $r['env']);
    }

    public static function test(array $t): array
    {
        self::rclone($t, ['mkdir', '@BASE'], 60);
        self::rclone($t, ['lsf', '--max-depth', '1', '@BASE'], 60);
        return [];
    }

    // ---- operations ------------------------------------------------------------------------------------------

    public static function create(array $a): array
    {
        [$acct, $pw] = self::account((string)($a['account'] ?? ''));
        $files = !empty($a['files']);
        $db = !empty($a['db']);
        $names = array_slice((array)($a['db_names'] ?? []), 0, 100);
        if (!$files && !$db) {
            throw new \InvalidArgumentException('Nothing selected');
        }
        is_dir(self::DIR) || mkdir(self::DIR, 0711, true);
        $dir = self::DIR . '/' . $acct;
        if (!is_dir($dir)) {
            mkdir($dir, 0750);
        }
        chown($dir, 'root');
        chgrp($dir, $acct);
        chmod($dir, 0750);
        $name = $acct . '-' . date('Ymd-His') . '.tar.gz';
        $out = "$dir/$name";
        $w = self::tmp();
        try {
            $hasDb = false;
            if ($db) {
                $dump = self::bin('/usr/bin/mariadb-dump', '/usr/bin/mysqldump');
                foreach ($names as $n) {
                    if (!is_string($n) || !preg_match('/^' . $acct . '_[a-z0-9_]{1,16}$/', $n)) {
                        throw new \InvalidArgumentException('Invalid database name');
                    }
                    [$c, $e] = Sys::runIO([$dump, '--single-transaction', '--quick', '--routines', '--events', '--triggers', '--default-character-set=utf8mb4', $n], null, "$w/db/$n.sql");
                    if ($c !== 0) {
                        throw new \RuntimeException("Database dump failed ($n): $e");
                    }
                    $hasDb = true;
                }
            }
            $cmd = [self::bin('/usr/bin/tar', '/bin/tar'), '-czf', $out];
            if ($hasDb) {
                array_push($cmd, '-C', $w, 'db');                          // db first: restore can stop reading early
            }
            if ($files && is_dir("/home/$acct/domains")) {
                array_push($cmd, '-C', "/home/$acct", 'domains');
            }
            if (count($cmd) === 3) {
                throw new \RuntimeException('Nothing to back up');
            }
            [$c, $e] = Sys::runIO($cmd, null, null);
            if ($c > 1) {                                                   // 1 = "file changed while reading": acceptable
                @unlink($out);
                throw new \RuntimeException('Archive creation failed: ' . $e);
            }
        } finally {
            self::cleanup($w);
        }
        chmod($out, 0640);
        chgrp($out, $acct);
        $remote = false;
        if (!empty($a['target']) && is_array($a['target'])) {
            self::rclone($a['target'], ['copyto', $out, '@BASE/' . $name]);
            $remote = true;
        }
        return ['file' => $name, 'size' => (int)filesize($out), 'remote' => $remote];
    }

    public static function restore(array $a): array
    {
        [$acct, $pw] = self::account((string)($a['account'] ?? ''));
        $name = self::file($acct, $a['file'] ?? '');
        $path = self::DIR . "/$acct/$name";
        if (!is_file($path)) {
            if (empty($a['target']) || !is_array($a['target'])) {
                throw new \RuntimeException('Backup file not found');
            }
            is_dir(dirname($path)) || mkdir(dirname($path), 0750);
            self::rclone($a['target'], ['copyto', '@BASE/' . $name, $path]);
            chmod($path, 0640);
            chgrp($path, $acct);
        }
        $tar = self::bin('/usr/bin/tar', '/bin/tar');
        $as = self::asUser($pw);
        $log = [];
        if (!empty($a['files'])) {
            [$c, $e] = Sys::runIO([...$as, $tar, '-xzf', $path, '-C', "/home/$acct", '--no-same-owner', 'domains']);
            $log[] = $c <= 1 ? 'files restored' : 'files: ' . $e;
        }
        if (!empty($a['db'])) {
            $client = self::bin('/usr/bin/mariadb', '/usr/bin/mysql');
            foreach (array_slice((array)($a['db_names'] ?? []), 0, 100) as $n) {
                if (!is_string($n) || !preg_match('/^' . $acct . '_[a-z0-9_]{1,16}$/', $n)) {
                    throw new \InvalidArgumentException('Invalid database name');
                }
                $w = self::tmp($pw);
                try {
                    [$c] = Sys::runIO([...$as, $tar, '-xzf', $path, '-C', $w, '--occurrence=1', "db/$n.sql"]);
                    if (!is_file("$w/db/$n.sql")) {
                        $log[] = "$n: not in archive";
                        continue;
                    }
                    // import with a throw-away MySQL user that can only touch this one database, client running as the tenant
                    $u = 'hvr' . bin2hex(random_bytes(5));
                    $p = bin2hex(random_bytes(12));
                    $g = str_replace('_', '\\_', $n);
                    self::sql("CREATE USER '$u'@'localhost' IDENTIFIED BY '$p';\nGRANT ALL PRIVILEGES ON `$g`.* TO '$u'@'localhost';\nFLUSH PRIVILEGES;\n");
                    try {
                        [$c, $e] = Sys::runIO([...$as, $client, '-u', $u, $n], "$w/db/$n.sql", null, ['MYSQL_PWD' => $p]);
                        $log[] = $c === 0 ? "$n restored" : "$n: " . $e;
                    } finally {
                        self::sql("DROP USER IF EXISTS '$u'@'localhost';\nFLUSH PRIVILEGES;\n");
                    }
                } finally {
                    self::cleanup($w);
                }
            }
        }
        return ['log' => $log];
    }

    public static function delete(array $a): array
    {
        $acct = Validator::account($a['account'] ?? '');
        $name = self::file($acct, $a['file'] ?? '');
        @unlink(self::DIR . "/$acct/$name");
        if (!empty($a['target']) && is_array($a['target'])) {
            try {
                self::rclone($a['target'], ['deletefile', '@BASE/' . $name], 120);
            } catch (\Throwable) {
            }
        }
        return [];
    }
}
