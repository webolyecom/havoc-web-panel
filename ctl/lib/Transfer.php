<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Site transfer between two Havoc panels (root side).
 *
 * Source: export() packs one domain into /var/backups/havoc/transfer/<id>/ (files.tar.gz built by tar running as the
 * account, one gzipped SQL dump per database, optional ssl.json); the panel streams these files to the other panel.
 *
 * Destination: the panel downloads into /var/backups/havoc/incoming/<id>/ and calls the import steps. Everything that
 * interprets content from the other server runs as the receiving hosting account (tar, the SQL client with a throw-away
 * MySQL user limited to one database, config file edits, WP-CLI), exactly like a backup restore.
 */
final class Transfer
{
    private const OUT = '/var/backups/havoc/transfer';
    private const IN  = '/var/backups/havoc/incoming';
    private const CONFIG_FILES = ['wp-config.php', '.env', 'config.php', 'configuration.php', 'app/etc/env.php', 'includes/config.php',
        'config/database.php', 'sites/default/settings.php', '.user.ini', '.htaccess', 'wp-content/wp-cache-config.php'];

    private static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid transfer id');
        }
        return $id;
    }

    private static function account(mixed $acct): array
    {
        $acct = Validator::account((string)$acct);
        $pw = posix_getpwnam($acct);
        if (!$pw || (int)$pw['uid'] === 0 || !is_dir("/home/$acct")) {
            throw new \RuntimeException('Hosting account does not exist');
        }
        return [$acct, $pw];
    }

    private static function dbName(string $acct, mixed $n): string
    {
        if (!is_string($n) || !preg_match('/^' . $acct . '_[a-z0-9_]{1,16}$/D', $n)) {
            throw new \InvalidArgumentException('Invalid database name');
        }
        return $n;
    }

    private static function client(): string
    {
        return is_file('/usr/bin/mariadb') ? '/usr/bin/mariadb' : '/usr/bin/mysql';
    }

    private static function sql(string $sql): string
    {
        return Sys::ok([self::client(), '--batch', '-N'], $sql);
    }

    // ---- source -------------------------------------------------------------------------------------------------

    /** Sizes of domains and databases, and which databases each site's config files mention (for the selection list). */
    public static function sizes(array $a): array
    {
        $out = [];
        foreach (array_slice((array)($a['items'] ?? []), 0, 500) as $it) {
            try {
                [$acct] = self::account($it['account'] ?? '');
                $dom = Validator::domain((string)($it['domain'] ?? ''));
            } catch (\Throwable) {
                continue;
            }
            $dir = "/home/$acct/domains/$dom";
            $size = 0;
            if (is_dir($dir)) {
                [$c, $o] = Sys::run(['du', '-sb', '--', $dir], null, 120);
                $size = $c === 0 ? (int)$o : 0;
            }
            $dbs = [];
            $files = [];
            foreach (self::CONFIG_FILES as $f) {
                foreach (["$dir/public_html/$f", "$dir/$f"] as $p) {
                    if (is_file($p)) {
                        $files[] = $p;
                    }
                }
            }
            foreach (array_slice((array)($it['dbs'] ?? []), 0, 100) as $n) {
                try {
                    $n = self::dbName($acct, $n);
                } catch (\Throwable) {
                    continue;
                }
                $bytes = (int)self::sql("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = '$n';");
                $used = false;
                if ($files) {
                    // read as the account: a symlinked config file cannot make root read anything
                    [$c] = Sys::run([...Sys::asUser($acct), 'grep', '-s', '-q', '-F', '-e', $n, '--', ...$files], null, 30);
                    $used = $c === 0;
                }
                $dbs[$n] = ['size' => $bytes, 'used' => $used];
            }
            $out[$dom] = ['size' => $size, 'dbs' => $dbs];
        }
        return ['sizes' => $out];
    }

    public static function export(array $a): array
    {
        $id = self::id($a['id'] ?? '');
        [$acct, $pw] = self::account($a['account'] ?? '');
        $dom = Validator::domain((string)($a['domain'] ?? ''));
        if (!is_dir("/home/$acct/domains/$dom")) {
            throw new \RuntimeException('Site folder not found');
        }
        is_dir(self::OUT) || mkdir(self::OUT, 0750, true);
        chgrp(self::OUT, 'havoc');
        $dir = self::OUT . "/$id";
        if (is_dir($dir)) {
            Sys::run(['rm', '-rf', '--', $dir]);
        }
        mkdir($dir, 0750);
        chgrp($dir, 'havoc');
        $free = (int)disk_free_space(self::OUT);
        $need = (int)Sys::run(['du', '-sb', '--', "/home/$acct/domains/$dom"], null, 300)[1];
        if ($need > $free * 0.9) {
            throw new \RuntimeException('Not enough free disk space on the source server for the export');
        }
        $tar = is_file('/usr/bin/tar') ? '/usr/bin/tar' : '/bin/tar';
        [$c, $e] = Sys::runIO([...Sys::asUser($acct), $tar, '-czf', '-', '-C', "/home/$acct/domains", $dom], null, "$dir/files.tar.gz");
        if ($c > 1) {
            throw new \RuntimeException('Packing the files failed: ' . mb_substr($e, 0, 300));
        }
        $users = [];
        $dump = is_file('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : '/usr/bin/mysqldump';
        foreach (array_slice((array)($a['dbs'] ?? []), 0, 50) as $n) {
            $n = self::dbName($acct, $n);
            [$c, $e] = Sys::runIO([$dump, '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4', $n], null, "$dir/db-$n.sql");
            if ($c !== 0) {
                throw new \RuntimeException("Database dump failed ($n): " . mb_substr($e, 0, 300));
            }
            Sys::ok(['gzip', '-6', '-f', "$dir/db-$n.sql"], null, 3600);
            // the password hash of the database user, so the site's config keeps working without a new password
            [$c, $o] = Sys::run([self::client(), '--batch', '-N', '-e', "SHOW CREATE USER `$n`@`localhost`"]);
            $users[$n] = $c === 0 && preg_match("/IDENTIFIED BY PASSWORD '(\\*[0-9A-F]{40})'/", $o, $m) ? $m[1] : null;
        }
        if (!empty($a['ssl'])) {
            $live = in_array($a['ssl_mode'] ?? '', ['custom', 'cloudflare'], true) ? "/etc/havoc/ssl/$dom" : "/etc/letsencrypt/live/$dom";
            $cert = @file_get_contents("$live/fullchain.pem");
            $key = @file_get_contents("$live/privkey.pem");
            if ($cert && $key) {
                Sys::write("$dir/ssl.json", json_encode(['cert' => $cert, 'key' => $key]), 0640, 'root', 'havoc');
            }
        }
        $files = [];
        foreach (glob("$dir/*") ?: [] as $f) {
            chmod($f, 0640);
            chgrp($f, 'havoc');
            $files[] = ['name' => basename($f), 'size' => (int)filesize($f), 'sha256' => hash_file('sha256', $f)];
        }
        return ['files' => $files, 'db_users' => $users];
    }

    public static function exportDelete(array $a): array
    {
        $id = self::id($a['id'] ?? '');
        Sys::run(['rm', '-rf', '--', self::OUT . "/$id"]);
        return [];
    }

    // ---- destination --------------------------------------------------------------------------------------------

    /** A download folder only the panel user can write. */
    public static function stage(array $a): array
    {
        $id = self::id($a['id'] ?? '');
        is_dir(self::IN) || mkdir(self::IN, 0711, true);
        $dir = self::IN . "/$id";
        if (!empty($a['remove'])) {
            Sys::run(['rm', '-rf', '--', $dir]);
            return [];
        }
        is_dir($dir) || mkdir($dir, 0700);
        chown($dir, 'havoc');
        chgrp($dir, 'havoc');
        return ['dir' => $dir, 'free' => (int)disk_free_space('/home')];
    }

    private static function staged(mixed $id, mixed $name, string $pattern): string
    {
        $id = self::id($id);
        if (!is_string($name) || !preg_match($pattern, $name)) {
            throw new \InvalidArgumentException('Invalid file name');
        }
        $p = self::IN . "/$id/$name";
        if (!is_file($p) || is_link($p)) {
            throw new \RuntimeException("Downloaded file missing: $name");
        }
        return $p;
    }

    public static function importFiles(array $a): array
    {
        [$acct] = self::account($a['account'] ?? '');
        $dom = Validator::domain((string)($a['domain'] ?? ''));
        $file = self::staged($a['id'] ?? '', 'files.tar.gz', '/^files\.tar\.gz$/D');
        $root = "/home/$acct/domains/$dom/public_html";
        // the panel's own welcome page would hide the site's index.php
        if (is_file("$root/index.html") && !is_link("$root/index.html") && str_contains((string)file_get_contents("$root/index.html", false, null, 0, 4096), 'Hosted with Havoc WEB Panel')) {
            Sys::run([...Sys::asUser($acct), 'rm', '-f', '--', "$root/index.html"]);
        }
        $tar = is_file('/usr/bin/tar') ? '/usr/bin/tar' : '/bin/tar';
        // extracted by the account itself, only the domain's own folder from the archive
        [$c, $e] = Sys::runIO([...Sys::asUser($acct), $tar, '-xzf', '-', '-C', "/home/$acct/domains", '--no-same-owner', '--no-overwrite-dir', $dom], $file, null);
        if ($c > 1) {
            throw new \RuntimeException('Unpacking the files failed: ' . mb_substr($e, 0, 300));
        }
        [, $o] = Sys::run(['du', '-sb', '--', "/home/$acct/domains/$dom"], null, 300);
        return ['size' => (int)$o];
    }

    public static function importDb(array $a): array
    {
        [$acct, $pw] = self::account($a['account'] ?? '');
        $n = self::dbName($acct, $a['name'] ?? '');
        $file = self::staged($a['id'] ?? '', $a['file'] ?? '', '/^db-[a-z0-9_]{1,40}\.sql\.gz$/D');
        $w = '/var/tmp/havoc-tr-' . bin2hex(random_bytes(6));
        mkdir($w, 0700);
        chown($w, (int)$pw['uid']);
        try {
            // decompress and drop DEFINER clauses (the old server's users do not exist here; the import user may not set them)
            $in = gzopen($file, 'rb');
            $out = fopen("$w/import.sql", 'wb');
            if (!$in || !$out) {
                throw new \RuntimeException('Cannot read the database dump');
            }
            while (!gzeof($in)) {
                $line = gzgets($in, 4 * 1024 * 1024);
                if ($line === false) {
                    break;
                }
                if (str_contains($line, 'DEFINER=')) {
                    $line = preg_replace('/\sDEFINER=`[^`]{1,80}`@`[^`]{1,255}`/', '', $line);
                }
                fwrite($out, $line);
            }
            gzclose($in);
            fclose($out);
            chown("$w/import.sql", (int)$pw['uid']);
            // imported with the site's own database user (it can only reach this database): triggers, views and routines
            // are then owned by that user, so they keep working after the import
            $pass = (string)($a['password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9!@#%^*_+=.\-]{12,64}$/D', $pass)) {
                throw new \InvalidArgumentException('Invalid database password');
            }
            [$c, $e] = Sys::runIO([...Sys::asUser($acct), self::client(), '-u', $n, $n], "$w/import.sql", null, ['MYSQL_PWD' => $pass]);
            if ($c !== 0) {
                throw new \RuntimeException("Database import failed ($n): " . mb_substr(trim($e), 0, 300));
            }
        } finally {
            Sys::run(['rm', '-rf', '--', $w]);
        }
        $hash = (string)($a['hash'] ?? '');
        $kept = false;
        if (preg_match('/^\*[0-9A-F]{40}$/D', $hash)) {
            // same password as on the old server: the site's configuration works unchanged
            self::sql("ALTER USER `$n`@`localhost` IDENTIFIED BY PASSWORD '$hash';\nFLUSH PRIVILEGES;\n");
            $kept = true;
        }
        return ['password_kept' => $kept];
    }

    /**
     * Replaces old names (database, database user, home path) in the site's configuration files, as the account.
     * $a['map'] = {old: new}; $a['wp_password'] = new DB password to put into wp-config.php (when the hash was not kept).
     */
    public static function patch(array $a): array
    {
        [$acct] = self::account($a['account'] ?? '');
        $dom = Validator::domain((string)($a['domain'] ?? ''));
        $map = [];
        foreach ((array)($a['map'] ?? []) as $old => $new) {
            if (is_string($old) && is_string($new) && preg_match('#^[A-Za-z0-9_./-]{3,200}$#D', $old) && preg_match('#^[A-Za-z0-9_./-]{3,200}$#D', $new)) {
                $map[$old] = $new;
            }
        }
        $pass = (string)($a['wp_password'] ?? '');
        if (!$map && $pass === '') {
            return ['changed' => []];
        }
        $base = "/home/$acct/domains/$dom";
        $files = [];
        foreach (self::CONFIG_FILES as $f) {
            $files[] = "$base/public_html/$f";
            $files[] = "$base/$f";
        }
        $code = <<<'PHP'
$in = json_decode(stream_get_contents(STDIN), true);
$changed = [];
foreach ($in['files'] as $f) {
    if (!is_file($f) || is_link($f) || filesize($f) > 2097152) continue;
    $s = file_get_contents($f);
    $n = strtr($s, $in['map']);
    if ($in['pass'] !== '' && basename($f) === 'wp-config.php') {
        $n = preg_replace("/(define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*)(['\"]).*?\\2/", '${1}' . var_export($in['pass'], true), $n);
    }
    if ($n !== $s && file_put_contents($f, $n) !== false) $changed[] = $f;
}
echo json_encode($changed);
PHP;
        [$c, $o, $e] = Sys::run([...Sys::asUser($acct), '/usr/bin/php', '-n', '-r', $code], json_encode(['files' => $files, 'map' => $map, 'pass' => $pass]), 60);
        if ($c !== 0) {
            throw new \RuntimeException('Updating configuration files failed: ' . mb_substr($e, 0, 200));
        }
        return ['changed' => array_map(fn($f) => substr($f, strlen($base) + 1), json_decode($o, true) ?: [])];
    }

    /** WordPress keeps absolute paths (and sometimes old database prefixes) in its database: search-replace with WP-CLI. */
    public static function wpReplace(array $a): array
    {
        [$acct] = self::account($a['account'] ?? '');
        $dom = Validator::domain((string)($a['domain'] ?? ''));
        $old = (string)($a['old'] ?? '');
        $new = (string)($a['new'] ?? '');
        if (!preg_match('#^/home/hv\d+/domains/[a-z0-9.-]+$#D', $old) || !preg_match('#^/home/hv\d+/domains/[a-z0-9.-]+$#D', $new)) {
            throw new \InvalidArgumentException('Invalid paths');
        }
        $root = "/home/$acct/domains/$dom/public_html";
        if (!is_file("$root/wp-config.php") || !is_file("$root/wp-includes/version.php")) {
            return ['wordpress' => false];
        }
        $wp = AppInstaller::wpcliPath();
        $php = is_file('/usr/bin/php' . ($a['php'] ?? '')) ? '/usr/bin/php' . $a['php'] : '/usr/bin/php';
        $home = "/home/$acct";
        [$c, $o, $e] = Sys::run([...Sys::asUser($acct), '/usr/bin/env', '-C', $root, $php, '-d', 'memory_limit=512M', $wp, '--path=' . $root,
            'search-replace', $old, $new, '--all-tables-with-prefix', '--skip-columns=guid', '--quiet'], null, 1800,
            ['HOME' => $home, 'WP_CLI_CACHE_DIR' => "$home/.wp-cli/cache", 'WP_CLI_DISABLE_AUTO_CHECK_UPDATE' => '1']);
        return ['wordpress' => true, 'ok' => $c === 0, 'note' => $c === 0 ? '' : mb_substr(trim($e ?: $o), 0, 300)];
    }

    /** Does the site answer on this server? (asked locally, DNS still points to the old server) */
    public static function check(array $a): array
    {
        $dom = Validator::domain((string)($a['domain'] ?? ''));
        $res = [];
        foreach (['http' => 80, 'https' => 443] as $scheme => $port) {
            [, $o] = Sys::run(['curl', '-s', '-k', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '20', '--noproxy', '*',
                '--resolve', "$dom:$port:127.0.0.1", "$scheme://$dom/"], null, 30);
            $res[$scheme] = (int)$o;
        }
        return $res;
    }
}
