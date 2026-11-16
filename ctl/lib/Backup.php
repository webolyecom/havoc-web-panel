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
        if (!is_string($f) || !preg_match('/^' . $acct . '-[0-9]{8}-[0-9]{6}\.tar\.gz$/D', $f)) {
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

    // ---- remote targets (rclone: environment variables; OAuth services through a temporary config file) ----------

    public const OAUTH = ['gdrive' => 'drive', 'onedrive' => 'onedrive', 'dropbox' => 'dropbox'];

    /** A refreshed OAuth token written back by rclone during the last call (the panel stores it). */
    private static ?string $newToken = null;

    /** @return array{env:array,base:string,conf?:string,root?:string} */
    public static function target(array $t): array
    {
        $r = self::plainTarget($t);
        $pass = (string)($t['crypt'] ?? '');
        if ($pass !== '') {
            // optional client-side encryption: rclone crypt wrapped around the remote (file names stay readable)
            if (strlen($pass) < 8 || strlen($pass) > 200 || str_contains($pass, "\0")) {
                throw new \InvalidArgumentException('Encryption password must be 8-200 characters');
            }
            $r['root'] = $r['base'];
            $r['env'] += ['RCLONE_CONFIG_HVC_TYPE' => 'crypt', 'RCLONE_CONFIG_HVC_REMOTE' => $r['base'],
                'RCLONE_CONFIG_HVC_PASSWORD' => trim(Sys::ok(['rclone', 'obscure', '-'], $pass)),
                'RCLONE_CONFIG_HVC_FILENAME_ENCRYPTION' => 'off', 'RCLONE_CONFIG_HVC_DIRECTORY_NAME_ENCRYPTION' => 'false'];
            $r['base'] = 'HVC:';
        }
        return $r;
    }

    /** @return array{env:array,base:string,conf?:string} */
    private static function plainTarget(array $t): array
    {
        $type = $t['type'] ?? '';
        if (isset(self::OAUTH[$type])) {
            $path = trim((string)($t['path'] ?? ''), '/');
            if (!preg_match('#^[A-Za-z0-9._\- /]{1,200}$#D', $path) || str_contains($path, '..')) {
                throw new \InvalidArgumentException('Invalid folder name');
            }
            $tok = json_decode((string)($t['token'] ?? ''), true);
            if (!is_array($tok) || !is_string($tok['access_token'] ?? null) || strlen((string)$t['token']) > 8192) {
                throw new \InvalidArgumentException('Not connected: missing or invalid token');
            }
            $conf = "[HV]\ntype = " . self::OAUTH[$type] . "\ntoken = " . json_encode($tok, JSON_UNESCAPED_SLASHES) . "\n";
            if ($type === 'gdrive') {
                $conf .= "scope = drive.file\n";
            } elseif ($type === 'onedrive') {
                $id = (string)($t['drive_id'] ?? '');
                $dt = (string)($t['drive_type'] ?? '');
                if (!preg_match('/^[A-Za-z0-9!_.\-]{1,128}$/D', $id) || !in_array($dt, ['personal', 'business', 'documentLibrary'], true)) {
                    throw new \InvalidArgumentException('Invalid OneDrive drive');
                }
                $conf .= "drive_id = $id\ndrive_type = $dt\n";
            }
            return ['env' => [], 'base' => 'HV:' . $path, 'conf' => $conf];
        }
        $path = (string)($t['path'] ?? '');
        $path = (string)($t['path'] ?? '');
        if (!preg_match('#^[A-Za-z0-9._\-/]{0,200}$#D', $path) || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid remote path');
        }
        $env = [];
        if ($type === 'sftp' || $type === 'ftp') {
            $host = (string)($t['host'] ?? '');
            self::checkHost($host);
            $port = (int)(($t['port'] ?? 0) ?: ($type === 'sftp' ? 22 : 21));
            $user = (string)($t['user'] ?? '');
            $pass = (string)($t['password'] ?? '');
            if ($port < 1 || $port > 65535 || !preg_match('/^[A-Za-z0-9._@\-]{1,64}$/D', $user) || $pass === '' || strlen($pass) > 200 || str_contains($pass, "\0")) {
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
            if (!preg_match('/^[a-z0-9][a-z0-9.\-]{2,62}$/D', $bucket) || !preg_match('/^[A-Za-z0-9+\/=_\-]{8,128}$/D', $ak) || !preg_match('/^[A-Za-z0-9+\/=_\-]{16,128}$/D', $sk)
                || !preg_match('/^[a-z0-9\-]{2,30}$/D', $region)) {
                throw new \InvalidArgumentException('Invalid S3 settings');
            }
            $env = ['RCLONE_CONFIG_HV_TYPE' => 's3', 'RCLONE_CONFIG_HV_PROVIDER' => $endpoint ? 'Other' : 'AWS', 'RCLONE_CONFIG_HV_ACCESS_KEY_ID' => $ak,
                    'RCLONE_CONFIG_HV_SECRET_ACCESS_KEY' => $sk, 'RCLONE_CONFIG_HV_REGION' => $region];
            if ($endpoint !== '') {
                if (!preg_match('#^https://([A-Za-z0-9.\-]+)(:[0-9]{2,5})?$#D', $endpoint, $m)) {
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
        if (!preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?$/D', $h)) {
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
        $conf = '/dev/null';
        if (isset($r['conf'])) {
            // OAuth: rclone refreshes the access token and writes the new one back into this file
            $conf = '/var/tmp/havoc-rc-' . bin2hex(random_bytes(8)) . '.conf';
            Sys::write($conf, $r['conf'], 0600);
        }
        try {
            $map = fn($a) => match ($a) { '@BASE' => $r['base'], '@ROOT' => $r['root'] ?? $r['base'], default => $a };
            return Sys::ok(['rclone', '--config', $conf, '--contimeout', '20s', '--low-level-retries', '3', ...array_map($map, $args)], null, $timeout, $r['env']);
        } finally {
            if ($conf !== '/dev/null') {
                $after = (string)@file_get_contents($conf);
                @unlink($conf);
                if (preg_match('/^token = (\{.*\})$/m', $after, $m) && json_decode($m[1], true) && $m[1] !== json_encode(json_decode((string)$t['token'], true), JSON_UNESCAPED_SLASHES)) {
                    self::$newToken = $m[1];
                }
            }
        }
    }

    /** Adds a refreshed OAuth token to an action result. */
    private static function withToken(array $r): array
    {
        if (self::$newToken !== null) {
            $r['token'] = self::$newToken;
            self::$newToken = null;
        }
        return $r;
    }

    public static function test(array $t): array
    {
        self::rclone($t, ['mkdir', '@BASE'], 90);
        self::rclone($t, ['lsf', '--max-depth', '1', '@BASE'], 90);
        return self::withToken(self::aboutRaw($t));
    }

    /** Free and used space of the account (not every service reports it). */
    public static function about(array $t): array
    {
        return self::withToken(self::aboutRaw($t));
    }

    private static function aboutRaw(array $t): array
    {
        try {
            $j = json_decode(self::rclone($t, ['about', '--json', preg_replace('/:.*$/', ':', self::target($t)['root'] ?? self::target($t)['base'])], 60), true);
        } catch (\Throwable) {
            return ['about' => null];
        }
        return ['about' => is_array($j) ? array_intersect_key($j, array_flip(['total', 'used', 'free', 'trashed'])) : null];
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
                    if (!is_string($n) || !preg_match('/^' . $acct . '_[a-z0-9_]{1,16}$/D', $n)) {
                        throw new \InvalidArgumentException('Invalid database name');
                    }
                    [$c, $e] = Sys::runIO([$dump, '--single-transaction', '--quick', '--routines', '--events', '--triggers', '--default-character-set=utf8mb4', $n], null, "$w/db/$n.sql");
                    if ($c !== 0) {
                        throw new \RuntimeException("Database dump failed ($n): $e");
                    }
                    $hasDb = true;
                }
            }
            // search indexes of the account's sites (Meilisearch): settings + documents per index
            $hasMeili = false;
            if ($db && !empty($a['meili'])) {
                foreach (array_slice((array)$a['meili'], 0, 100) as $prefix) {
                    try {
                        $hasMeili = MeiliSites::export((string)$prefix, "$w/meili") > 0 || $hasMeili;
                    } catch (\Throwable $e) {
                        error_log('backup: search export: ' . $e->getMessage());
                    }
                }
                if ($hasMeili) {
                    Sys::run(['chown', '-R', $pw['uid'] . ':' . $pw['gid'], "$w/meili"]);
                }
            }
            // the archive is built by tar running as the tenant (it only reads what the tenant itself can read, so symlinks
            // or races inside the home directory gain nothing); root only opens the output file in the backup directory
            if ($hasDb || $hasMeili) {
                chgrp($w, (int)$pw['gid']);
                chmod($w, 0750);
                chgrp("$w/db", (int)$pw['gid']);
                chmod("$w/db", 0750);
                foreach (glob("$w/db/*.sql") ?: [] as $f) {
                    chgrp($f, (int)$pw['gid']);
                    chmod($f, 0640);
                }
            }
            $cmd = [...self::asUser($pw), self::bin('/usr/bin/tar', '/bin/tar'), '-czf', '-'];
            if ($hasDb) {
                array_push($cmd, '-C', $w, 'db');                          // db first: restore can stop reading early
            }
            if ($hasMeili) {
                array_push($cmd, '-C', $w, 'meili');
            }
            if ($files && is_dir("/home/$acct/domains")) {
                array_push($cmd, '-C', "/home/$acct", 'domains');
            }
            if (!$hasDb && !$hasMeili && !($files && is_dir("/home/$acct/domains"))) {
                throw new \RuntimeException('Nothing to back up');
            }
            [$c, $e] = Sys::runIO($cmd, null, $out);
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
        return self::withToken(['file' => $name, 'size' => (int)filesize($out), 'remote' => $remote]);
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
            [$c, $e] = Sys::runIO([...$as, $tar, '-xzf', $path, '-C', "/home/$acct", '--no-same-owner', 'domains'], null, null);
            $log[] = $c <= 1 ? 'files restored' : 'files: ' . $e;
        }
        if (!empty($a['db'])) {
            $client = self::bin('/usr/bin/mariadb', '/usr/bin/mysql');
            foreach (array_slice((array)($a['db_names'] ?? []), 0, 100) as $n) {
                if (!is_string($n) || !preg_match('/^' . $acct . '_[a-z0-9_]{1,16}$/D', $n)) {
                    throw new \InvalidArgumentException('Invalid database name');
                }
                // is it in the archive? (db/ comes first, so tar stops early)
                [$c, $lst] = Sys::run([...$as, $tar, '-tzf', $path, '--occurrence=1', "db/$n.sql"], null, 600);
                if ($c !== 0 || trim($lst) !== "db/$n.sql") {
                    $log[] = "$n: not in archive";
                    continue;
                }
                // imported as the database's own user, so triggers, views and routines are owned by it and keep working
                // (their DEFINER clauses are dropped: only SUPER may set another one). Its password is set to a temporary
                // one for the import and the original hash is put back afterwards. Without a usable hash (e.g. the user
                // is missing) a throw-away user that can only reach this database does the import.
                [$c, $o] = Sys::run([$client, '--batch', '-N', '-e', "SHOW CREATE USER `$n`@`localhost`"]);
                $hash = $c === 0 && preg_match("/IDENTIFIED BY PASSWORD '(\\*[0-9A-F]{40})'/", $o, $m) ? $m[1] : null;
                $p = bin2hex(random_bytes(12));
                if ($hash) {
                    $u = $n;
                    self::sql("ALTER USER `$n`@`localhost` IDENTIFIED BY '$p';\nFLUSH PRIVILEGES;\n");
                } else {
                    $u = 'hvr' . bin2hex(random_bytes(5));
                    $g = str_replace('_', '\\_', $n);
                    self::sql("CREATE USER '$u'@'localhost' IDENTIFIED BY '$p';\nGRANT ALL PRIVILEGES ON `$g`.* TO '$u'@'localhost';\nFLUSH PRIVILEGES;\n");
                }
                try {
                    // the whole pipeline runs as the tenant: root never reads a file the tenant could swap
                    $sh = 'set -o pipefail; "$1" -xzOf "$2" --occurrence=1 "db/$3.sql" | sed -E \'s/ DEFINER=`[^`]{1,80}`@`[^`]{1,255}`//g\' | "$4" -u "$5" "$3"';
                    [$c, $e] = Sys::runIO([...$as, '/bin/bash', '-c', $sh, 'restore', $tar, $path, $n, $client, $u], null, null, ['MYSQL_PWD' => $p]);
                    $log[] = $c === 0 ? "$n restored" : "$n: " . mb_substr(trim($e), 0, 400);
                } finally {
                    self::sql($hash
                        ? "ALTER USER `$n`@`localhost` IDENTIFIED BY PASSWORD '$hash';\nFLUSH PRIVILEGES;\n"
                        : "DROP USER IF EXISTS '$u'@'localhost';\nFLUSH PRIVILEGES;\n");
                }
            }
        }
        if (!empty($a['db']) && !empty($a['meili'])) {
            $w = self::tmp($pw);
            try {
                [$c] = Sys::runIO([...$as, $tar, '-xzf', $path, '-C', $w, 'meili'], null, null);
                if (is_dir("$w/meili") && !is_link("$w/meili")) {
                    $safe = MeiliSites::lockIn("$w/meili");
                    $w2 = dirname($safe);
                    foreach (array_slice((array)$a['meili'], 0, 100) as $prefix) {
                        try {
                            $n = count(MeiliSites::import($safe, (string)$prefix, (string)$prefix));
                            $n && $log[] = "search: $n index(es) restored";
                        } catch (\Throwable $e) {
                            $log[] = 'search: ' . $e->getMessage();
                        }
                    }
                    Sys::run(['rm', '-rf', '--', $w2]);
                }
            } finally {
                self::cleanup($w);
            }
        }
        return self::withToken(['log' => $log]);
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
        return self::withToken([]);
    }
}
