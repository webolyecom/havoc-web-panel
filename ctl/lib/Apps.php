<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Applications (root helper for the panel's WebAppService). A long-running process bound to a site's domain:
 *   /opt/havoc-apps/<name>/releases/<UTC ts>/   each deploy
 *   /opt/havoc-apps/<name>/current -> releases/..  atomic symlink
 *   /opt/havoc-apps/<name>/shared/<path>         kept across deploys (live content, never overwritten after the first)
 *   /etc/havoc-apps/<name>.env                   0600 root:havoc-<name>, PORT/ADDR written automatically
 *   havoc-app-<name>.service                     runs as havoc-<name>, hardened
 * Everything runs through argv (never a shell); file and process actions run as the per-app user havoc-<name> (never root);
 * the port is reachable only on 127.0.0.1 (nginx proxies to it; the firewall never opens it).
 */
final class Apps
{
    private const ROOT = '/opt/havoc-apps';
    private const ENVDIR = '/etc/havoc-apps';
    private const RESERVED_ENV = ['PORT', 'ADDR'];
    private const KEEP = 5;

    private static function name(array $a): string
    {
        $n = strtolower(trim((string)($a['name'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $n)) {
            throw new \InvalidArgumentException('Invalid application name');
        }
        return $n;
    }

    private static function user(string $n): string
    {
        return 'havoc-' . $n;
    }

    private static function base(string $n): string
    {
        return self::ROOT . '/' . $n;
    }

    private static function unit(string $n): string
    {
        return 'havoc-app-' . $n . '.service';
    }

    private static function envFile(string $n): string
    {
        return self::ENVDIR . '/' . $n . '.env';
    }

    /** The binary (first token of start_command, './' stripped) and its arguments. */
    private static function startParts(string $start): array
    {
        $parts = preg_split('/\s+/', trim($start)) ?: [];
        $bin = preg_replace('#^\./#', '', (string)array_shift($parts));
        if ($bin === '' || str_contains($bin, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $bin)) {
            throw new \InvalidArgumentException('Invalid start command');
        }
        return [$bin, $parts];
    }

    private static function ensureUser(string $user): array
    {
        $pw = posix_getpwnam($user);
        if (!$pw) {
            Sys::ok(['useradd', '--system', '--no-create-home', '--shell', '/usr/sbin/nologin', $user]);
            $pw = posix_getpwnam($user) ?: throw new \RuntimeException('Could not create the application user');
        }
        if ((int)$pw['uid'] === 0) {
            throw new \RuntimeException('Refusing to use a privileged user');
        }
        return $pw;
    }

    /** A safe, relative sub-path under a base directory (no absolute, no "..", no symlink component). */
    private static function safeUnder(string $base, string $rel): string
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, "\0") || preg_match('#(^|/)\.\.(/|$)#', $rel) || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
            throw new \InvalidArgumentException('Invalid path');
        }
        $full = $base . '/' . $rel;
        $parent = dirname($full);
        $realParent = realpath($parent);
        if ($realParent !== false && !str_starts_with($realParent . '/', realpath($base) . '/') && $realParent !== realpath($base)) {
            throw new \InvalidArgumentException('Path escapes the application directory');
        }
        return $full;
    }

    // ---- provisioning -------------------------------------------------------

    public static function provision(array $a): array
    {
        $n = self::name($a);
        $user = self::user($n);
        $port = (int)($a['port'] ?? 0);
        if ($port < 20000 || $port > 29999) {
            throw new \InvalidArgumentException('Port out of range');
        }
        self::startParts((string)($a['start_command'] ?? ''));     // validate
        self::ensureUser($user);
        $base = self::base($n);
        foreach ([self::ROOT => '0755', $base => '0755', "$base/releases" => '0755'] as $dir => $mode) {
            is_dir($dir) || mkdir($dir, octdec($mode), true);
            chmod($dir, octdec($mode));
            chown($dir, 'root');
            chgrp($dir, 'root');
        }
        is_dir("$base/shared") || mkdir("$base/shared", 0750, true);
        Sys::ok(['chown', $user . ':' . $user, "$base/shared"]);
        chmod("$base/shared", 0750);
        // shared sub-paths are NOT created here: the first deploy seeds them from the release, then never overwrites them.
        // env file (PORT/ADDR always; keep any already-set keys, add the provided ones)
        $env = self::readEnv($n);
        $env['PORT'] = (string)$port;
        $env['ADDR'] = '127.0.0.1:' . $port;
        foreach ((array)($a['env'] ?? []) as $k => $v) {
            if (self::validKey((string)$k) && !preg_match('/[\r\n\0]/', (string)$v)) {
                $env[(string)$k] = (string)$v;
            }
        }
        self::writeEnv($n, $env);
        self::writeUnit($n, (string)$a['start_command']);
        Sys::run(['systemctl', 'daemon-reload']);
        Sys::run(['systemctl', 'enable', self::unit($n)]);
        return ['name' => $n, 'port' => $port, 'user' => $user];
    }

    private static function writeUnit(string $n, string $start): void
    {
        [$bin, $args] = self::startParts($start);
        $base = self::base($n);
        $exec = "$base/current/$bin" . ($args ? ' ' . implode(' ', $args) : '');
        $user = self::user($n);
        $unit = "[Unit]\nDescription=Havoc application $n\nAfter=network.target\n\n"
            . "[Service]\nUser=$user\nGroup=$user\nWorkingDirectory=$base/current\nEnvironmentFile=" . self::envFile($n) . "\n"
            . "ExecStart=$exec\nRestart=always\nRestartSec=2\nKillSignal=SIGTERM\nTimeoutStopSec=20\nLimitNOFILE=65535\n"
            . "NoNewPrivileges=true\nPrivateTmp=true\nProtectSystem=strict\nProtectHome=true\nReadWritePaths=$base/shared\n\n"
            . "[Install]\nWantedBy=multi-user.target\n";
        Sys::write('/etc/systemd/system/' . self::unit($n), $unit, 0644);
    }

    // ---- environment --------------------------------------------------------

    private static function validKey(string $k): bool
    {
        return (bool)preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/', $k);
    }

    private static function readEnv(string $n): array
    {
        $out = [];
        foreach (@file(self::envFile($n)) ?: [] as $line) {
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', rtrim($line, "\r\n"), $m)) {
                $out[$m[1]] = $m[2];
            }
        }
        return $out;
    }

    private static function writeEnv(string $n, array $env): void
    {
        $body = "# Managed by Havoc WEB Panel\n";
        foreach ($env as $k => $v) {
            if (self::validKey((string)$k)) {
                $body .= $k . '=' . str_replace(["\r", "\n"], '', (string)$v) . "\n";
            }
        }
        self::writeEnv2($n, $body);
    }

    private static function writeEnv2(string $n, string $body): void
    {
        self::ensureUser(self::user($n));
        is_dir(self::ENVDIR) || mkdir(self::ENVDIR, 0755, true);
        Sys::write(self::envFile($n), $body, 0600, 'root', self::user($n));
    }

    public static function env(array $a): array
    {
        $n = self::name($a);
        $env = self::readEnv($n);
        $changed = false;
        foreach ((array)($a['set'] ?? []) as $k => $v) {
            $k = (string)$k;
            if (self::validKey($k) && !in_array($k, self::RESERVED_ENV, true) && !preg_match('/[\r\n\0]/', (string)$v)) {
                $env[$k] = (string)$v;
                $changed = true;
            }
        }
        foreach ((array)($a['unset'] ?? []) as $k) {
            $k = (string)$k;
            if (isset($env[$k]) && !in_array($k, self::RESERVED_ENV, true)) {
                unset($env[$k]);
                $changed = true;
            }
        }
        if ($changed) {
            self::writeEnv($n, $env);
        }
        $restarted = false;
        if ($changed && !empty($a['restart']) && self::isActive($n)) {
            Sys::run(['systemctl', 'restart', self::unit($n)]);
            $restarted = true;
        }
        $keys = array_keys($env);
        sort($keys);
        return ['keys' => $keys, 'changed' => $changed, 'restarted' => $restarted];   // values are never returned
    }

    // ---- deploy / rollback --------------------------------------------------

    /**
     * Resolve the uploaded archive a deploy should use. The panel passes the account (hvN), the app's domain and
     * `src` — the path list_uploads returned, relative to the domain's public_html (e.g.
     * ".havoc-incoming/20261127-201458-starminer-0.1.0.tar.gz"). The path is resolved against that web root and
     * normalised with realpath; it is accepted only if it is a regular file (not a symlink) still inside the root with a
     * supported archive extension. Path traversal is caught by the realpath-inside-root check, so the hidden
     * ".havoc-incoming" upload folder is allowed — there is no "hidden folder" ban here.
     */
    private static function resolveUpload(array $a): string
    {
        $account = (string)($a['account'] ?? '');
        $domain  = strtolower(trim((string)($a['domain'] ?? '')));
        $src     = trim(str_replace('\\', '/', (string)($a['src'] ?? '')), '/');
        if (!preg_match('#^hv\d+$#', $account)) {
            throw new \InvalidArgumentException('Invalid account');
        }
        if (!preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain)) {
            throw new \InvalidArgumentException('Invalid application domain');
        }
        if ($src === '' || str_contains($src, "\0") || !preg_match('#^[A-Za-z0-9._/-]+$#', $src) || preg_match('#(^|/)\.\.(/|$)#', $src)) {
            throw new \InvalidArgumentException('Invalid upload path: ' . $src);
        }
        if (!preg_match('/\.(zip|tar\.gz|tgz|tar)$/i', $src)) {   // .tar.gz matched as one extension
            throw new \InvalidArgumentException('Unsupported archive type (use .zip, .tar.gz, .tgz or .tar): ' . $src);
        }
        $root = realpath("/home/$account/domains/$domain/public_html");
        if ($root === false) {
            throw new \RuntimeException("Site web root not found for $domain");
        }
        $path = $root . '/' . $src;
        if (is_link($path)) {
            throw new \RuntimeException('Upload path is a symlink: ' . $src);
        }
        $archive = realpath($path);
        if ($archive === false || !is_file($archive)) {
            throw new \RuntimeException('Upload not found: ' . $src);
        }
        if ($archive !== $root && !str_starts_with($archive, $root . '/')) {
            throw new \RuntimeException('Upload is outside the site root: ' . $src);
        }
        return $archive;
    }

    public static function deploy(array $a): array
    {
        $n = self::name($a);
        $user = self::user($n);
        self::ensureUser($user);
        $base = self::base($n);
        if (!is_dir($base)) {
            throw new \RuntimeException('Application is not provisioned');
        }
        [$bin] = self::startParts((string)($a['start_command'] ?? ''));
        $ref = preg_match('/^[0-9]{8}-[0-9]{6}$/', (string)($a['ref'] ?? '')) ? $a['ref'] : gmdate('Ymd-His');
        $account = (string)($a['account'] ?? '');
        $archive = self::resolveUpload($a);
        $releaseDir = "$base/releases/$ref";
        if (is_dir($releaseDir)) {
            $releaseDir .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $ref = basename($releaseDir);
        }
        $prev = self::currentRef($n);

        $stage = "$base/releases/.stage-" . bin2hex(random_bytes(4));
        mkdir($stage, 0750, true);
        try {
            self::extract($archive, $stage);
            $rootDir = self::flattenTop($stage);
            // binary must exist as a regular file; make it executable
            $binPath = self::safeUnder($rootDir, $bin);
            if (!is_file($binPath) || is_link($binPath)) {
                throw new \RuntimeException("Start command not found in the archive: $bin");
            }
            @chmod($binPath, 0755);
            // shared paths: seed on first deploy, then link (never overwrite)
            foreach ((array)($a['shared_paths'] ?? []) as $sp) {
                self::linkShared($base, $rootDir, (string)$sp, $user);
            }
            if ($rootDir !== $releaseDir) {
                rename($rootDir, $releaseDir);
            }
            Sys::ok(['chown', '-R', $user . ':' . $user, $releaseDir]);
            Sys::ok(['chmod', '0750', $releaseDir]);
        } finally {
            is_dir($stage) && Sys::run(['rm', '-rf', $stage]);
        }

        // the archive has been consumed into the release; don't leave it in the web root (best-effort, as the account)
        Sys::run([...Sys::asUser($account), 'rm', '-f', '--', $archive]);

        // activate atomically and restart
        self::swap($n, $ref);
        Sys::run(['systemctl', 'restart', self::unit($n)]);
        $health = self::waitHealth((int)$a['port'], (string)($a['health_path'] ?? '/healthz'), 20);

        $result = ['ref' => $ref, 'health' => $health, 'rolled_back' => false, 'note' => ''];
        if (!$health) {
            $log = self::tailLog($n, 100);
            if ($prev !== null && $prev !== $ref && is_dir("$base/releases/$prev")) {
                self::swap($n, $prev);
                Sys::run(['systemctl', 'restart', self::unit($n)]);
                $back = self::waitHealth((int)$a['port'], (string)($a['health_path'] ?? '/healthz'), 20);
                $result['rolled_back'] = true;
                $result['active_ref'] = $prev;
                $result['note'] = "Health check failed; rolled back to $prev" . ($back ? '' : ' (previous release also unhealthy)') . ".\n\n" . $log;
            } else {
                $result['note'] = "Health check failed and there is no previous release to roll back to.\n\n" . $log;
            }
        }
        self::prune($n, (int)($a['keep'] ?? self::KEEP));
        return $result;
    }

    public static function rollback(array $a): array
    {
        $n = self::name($a);
        $base = self::base($n);
        $want = (string)($a['ref'] ?? '');
        $releases = self::releaseDirs($n);
        if ($want !== '') {
            if (!preg_match('/^[0-9]{8}-[0-9]{6}(-[0-9a-f]{4})?$/', $want) || !in_array($want, $releases, true)) {
                throw new \RuntimeException('Unknown release');
            }
            $target = $want;
        } else {
            $cur = self::currentRef($n);
            $target = null;
            foreach ($releases as $r) {                            // newest first; pick the newest that is not current
                if ($r !== $cur) {
                    $target = $r;
                    break;
                }
            }
            if ($target === null) {
                throw new \RuntimeException('No earlier release to roll back to');
            }
        }
        self::swap($n, $target);
        Sys::run(['systemctl', 'restart', self::unit($n)]);
        return ['ref' => $target, 'health' => self::waitHealth((int)$a['port'], (string)($a['health_path'] ?? '/healthz'), 20)];
    }

    /** Move a shared path out of the release on first deploy, then replace it with a symlink to shared (never overwrite). */
    private static function linkShared(string $base, string $rootDir, string $sp, string $user): void
    {
        $shared = self::safeUnder("$base/shared", $sp);
        $inRelease = self::safeUnder($rootDir, $sp);
        if (!is_dir($shared) && !is_file($shared)) {
            if (is_dir($inRelease) || is_file($inRelease)) {
                is_dir(dirname($shared)) || mkdir(dirname($shared), 0750, true);
                rename($inRelease, $shared);
            } else {
                mkdir($shared, 0750, true);
            }
            Sys::ok(['chown', '-R', $user . ':' . $user, $shared]);
        } elseif (is_dir($inRelease) || is_file($inRelease) || is_link($inRelease)) {
            Sys::run(['rm', '-rf', $inRelease]);                   // later deploys never overwrite live content
        }
        is_dir(dirname($inRelease)) || mkdir(dirname($inRelease), 0755, true);
        Sys::ok(['ln', '-sfn', $shared, $inRelease]);
    }

    private static function swap(string $n, string $ref): void
    {
        $base = self::base($n);
        if (!is_dir("$base/releases/$ref")) {
            throw new \RuntimeException('Release missing');
        }
        Sys::ok(['ln', '-sfn', "releases/$ref", "$base/current"]);
    }

    private static function currentRef(string $n): ?string
    {
        $t = @readlink(self::base($n) . '/current');
        return $t ? basename($t) : null;
    }

    private static function releaseDirs(string $n): array
    {
        $dirs = glob(self::base($n) . '/releases/*', GLOB_ONLYDIR) ?: [];
        $dirs = array_map('basename', array_filter($dirs, static fn($d) => !str_starts_with(basename($d), '.')));
        rsort($dirs, SORT_STRING);                                 // newest first (timestamp names)
        return $dirs;
    }

    private static function prune(string $n, int $keep): void
    {
        $keep = max(1, $keep);
        $cur = self::currentRef($n);
        $all = self::releaseDirs($n);
        $kept = 0;
        foreach ($all as $r) {
            $kept++;
            if ($kept <= $keep || $r === $cur) {
                continue;
            }
            Sys::run(['rm', '-rf', self::base($n) . '/releases/' . $r]);
        }
    }

    // ---- safe archive extraction (no symlinks, no path escapes) -------------

    private static function extract(string $archive, string $dest): void
    {
        if (preg_match('/\.zip$/i', $archive)) {
            $z = new \ZipArchive();
            if (($zc = $z->open($archive)) !== true) {
                throw new \RuntimeException('Archive could not be opened: zip error ' . $zc);
            }
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i);
                $name = $st['name'];
                $attr = $z->getExternalAttributesIndex($i, $op, $at) ? $at : 0;
                if ($op === \ZipArchive::OPSYS_UNIX && (($at >> 16) & 0170000) === 0120000) {
                    throw new \RuntimeException('Archive contains a symlink: ' . $name);
                }
                if (str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw new \RuntimeException('Unsafe path in archive: ' . $name);
                }
            }
            if (!$z->extractTo($dest)) {
                throw new \RuntimeException('Extraction failed');
            }
            $z->close();
        } elseif (preg_match('/\.(tar\.gz|tgz|tar)$/i', $archive)) {
            [$c, $o, $terr] = Sys::run(['tar', '-tf', $archive], null, 120);
            if ($c !== 0) {
                throw new \RuntimeException('Archive could not be opened: ' . (trim($terr) ?: 'not a readable tar archive'));
            }
            foreach (explode("\n", $o) as $name) {
                $name = trim($name);
                if ($name !== '' && (str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name))) {
                    throw new \RuntimeException('Unsafe path in archive: ' . $name);
                }
            }
            Sys::ok(['tar', '-xf', $archive, '-C', $dest, '--no-same-owner', '--no-same-permissions'], null, 300);
        } else {
            throw new \InvalidArgumentException('Supported archives: .zip, .tar.gz, .tgz, .tar');
        }
        // reject any symlink that slipped through (tar symlinks, nested)
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dest, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if ($f->isLink()) {
                throw new \RuntimeException('Archive contains a symlink: ' . $f->getFilename());
            }
        }
    }

    /** If everything sits inside a single top-level directory, use that as the release root. */
    private static function flattenTop(string $stage): string
    {
        $items = array_values(array_filter(scandir($stage) ?: [], static fn($x) => $x !== '.' && $x !== '..'));
        if (count($items) === 1 && is_dir("$stage/$items[0]") && !is_link("$stage/$items[0]")) {
            return "$stage/$items[0]";
        }
        return $stage;
    }

    // ---- control / status / logs -------------------------------------------

    private static function isActive(string $n): bool
    {
        return trim(Sys::run(['systemctl', 'is-active', self::unit($n)])[1]) === 'active';
    }

    public static function control(array $a): array
    {
        $n = self::name($a);
        $action = (string)($a['action'] ?? '');
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException('Invalid action');
        }
        if ($action !== 'stop' && !is_dir(self::base($n) . '/current')) {
            throw new \RuntimeException('Deploy the application first');
        }
        [$c, , $e] = Sys::run(['systemctl', $action, self::unit($n)], null, 40);
        if ($c !== 0) {
            throw new \RuntimeException('systemctl ' . $action . ': ' . trim($e));
        }
        return ['active' => self::isActive($n)];
    }

    public static function status(array $a): array
    {
        $n = self::name($a);
        $keys = ['ActiveState', 'SubState', 'MainPID', 'MemoryCurrent', 'CPUUsageNSec', 'ActiveEnterTimestamp', 'ExecMainStartTimestamp', 'NRestarts'];
        // parse KEY=VALUE, not `--value`: systemctl prints properties in its own order, so positional mapping misaligns them
        $show = Sys::run(['systemctl', 'show', self::unit($n), '-p', implode(',', $keys)])[1];
        $p = array_fill_keys($keys, '');
        foreach (explode("\n", trim($show)) as $line) {
            if (($eq = strpos($line, '=')) !== false && isset($p[$k = substr($line, 0, $eq)])) {
                $p[$k] = trim(substr($line, $eq + 1));
            }
        }
        $mem = ctype_digit($p['MemoryCurrent']) ? (int)$p['MemoryCurrent'] : null;
        $uptime = null;
        if (($ts = strtotime($p['ExecMainStartTimestamp'] ?: $p['ActiveEnterTimestamp'] ?: '')) !== false && $p['ActiveState'] === 'active') {
            $uptime = max(0, time() - $ts);
        }
        $health = ['ok' => false, 'status' => 0];
        if (!empty($a['port']) && $p['ActiveState'] === 'active') {
            [$ok, $code] = self::probe((int)$a['port'], (string)($a['health_path'] ?? '/healthz'));
            $health = ['ok' => $ok, 'status' => $code];
        }
        return ['active_state' => $p['ActiveState'], 'sub_state' => $p['SubState'], 'pid' => (int)$p['MainPID'] ?: null,
            'memory_bytes' => $mem, 'cpu_nsec' => ctype_digit($p['CPUUsageNSec']) ? (int)$p['CPUUsageNSec'] : null,
            'uptime_sec' => $uptime, 'restarts' => (int)$p['NRestarts'], 'current_release' => self::currentRef($n),
            'health' => $health, 'proxy' => self::proxyStatus($n, (string)($a['domain'] ?? ''))];
    }

    /** Reverse-proxy state of the app's vhost: the file, whether it is currently the proxy variant, and when it was last written. */
    private static function proxyStatus(string $n, string $domain): array
    {
        if ($domain === '' || !preg_match('/^[a-z0-9.-]{1,253}$/', $domain)) {
            return ['vhost_file' => null, 'active' => false, 'last_reload' => null];
        }
        $file = '/etc/nginx/conf.d/havoc-' . $domain . '.conf';
        $active = is_file($file) && str_contains((string)@file_get_contents($file), 'upstream havoc_app_' . $n . ' ');
        $mtime = is_file($file) ? @filemtime($file) : false;
        return ['vhost_file' => $file, 'active' => $active, 'last_reload' => $mtime ? gmdate('Y-m-d H:i:s', $mtime) . ' UTC' : null];
    }

    public static function logs(array $a): array
    {
        $n = self::name($a);
        $lines = max(1, min(2000, (int)($a['lines'] ?? 200)));
        $cmd = ['journalctl', '-u', self::unit($n), '--no-pager', '-n', (string)$lines, '-o', 'short-iso'];
        $since = (string)($a['since'] ?? '');
        if ($since !== '' && preg_match('#^[0-9 :+\-TZ]{1,40}$#', $since)) {
            array_push($cmd, '--since', $since);
        }
        [$c, $o, $e] = Sys::run($cmd, null, 40);
        $text = $c === 0 ? $o : trim($e ?: $o);
        $grep = (string)($a['grep'] ?? '');
        if ($grep !== '') {
            $text = implode("\n", array_filter(explode("\n", $text), static fn($l) => stripos($l, $grep) !== false));
        }
        return ['log' => mb_substr($text, -65536)];
    }

    private static function tailLog(string $n, int $lines): string
    {
        return self::logs(['name' => $n, 'lines' => $lines])['log'];
    }

    // ---- health / http ------------------------------------------------------

    private static function probe(int $port, string $path): array
    {
        $path = '/' . ltrim($path, '/');
        [$c, $o] = Sys::run(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '5',
            'http://127.0.0.1:' . $port . $path], null, 8);
        $code = $c === 0 ? (int)trim($o) : 0;
        return [$code >= 200 && $code < 400, $code];
    }

    private static function waitHealth(int $port, string $path, int $seconds): bool
    {
        $end = time() + max(1, $seconds);
        do {
            if (self::probe($port, $path)[0]) {
                return true;
            }
            usleep(500000);
        } while (time() < $end);
        return false;
    }

    public static function http(array $a): array
    {
        $n = self::name($a);
        $port = (int)($a['port'] ?? 0);
        $method = strtoupper((string)($a['method'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
            throw new \InvalidArgumentException('Invalid method');
        }
        $path = '/' . ltrim((string)($a['path'] ?? '/'), '/');
        if (preg_match('#[\s\0]#', $path) || str_contains($path, '://')) {
            throw new \InvalidArgumentException('Invalid path');
        }
        $cmd = ['curl', '-s', '-i', '--max-time', '60', '-X', $method, '--max-filesize', '67108864'];
        foreach ((array)($a['headers'] ?? []) as $k => $v) {
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/', (string)$k) && !preg_match('/[\r\n\0]/', (string)$v) && strtolower((string)$k) !== 'host') {
                array_push($cmd, '-H', $k . ': ' . $v);
            }
        }
        $body = (string)($a['body'] ?? '');
        if ($body !== '' && !in_array($method, ['GET', 'HEAD'], true)) {
            array_push($cmd, '--data-binary', '@-');
        } else {
            $body = null;
        }
        array_push($cmd, 'http://127.0.0.1:' . $port . $path);
        [$c, $o, $e] = Sys::run($cmd, $body, 70);
        if ($c !== 0 && $o === '') {
            throw new \RuntimeException('Request failed: ' . trim($e) . ' (is the application running?)');
        }
        // split the last response's headers from the body (curl -i; handle intermediate 1xx/100-continue)
        $parts = preg_split("/\r\n\r\n/", $o, -1);
        $head = '';
        $rest = $o;
        while (count($parts) > 1 && preg_match('#^HTTP/#', $parts[0])) {
            $head = array_shift($parts);
            $rest = implode("\r\n\r\n", $parts);
            if (!preg_match('#^HTTP/\S+ 1\d\d#', $head)) {
                break;
            }
            $parts = preg_split("/\r\n\r\n/", $rest, -1);
        }
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $head, $m) ? (int)$m[1] : 0;
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (str_contains($line, ':') && !str_starts_with($line, 'HTTP/')) {
                [$hk, $hv] = explode(':', $line, 2);
                $headers[trim($hk)] = trim($hv);
            }
        }
        $truncated = strlen($rest) > 65536;
        return ['status' => $status, 'headers' => $headers, 'body' => mb_substr($rest, 0, 65536), 'truncated' => $truncated];
    }

    public static function exec(array $a): array
    {
        $n = self::name($a);
        $user = self::user($n);
        $base = self::base($n);
        $cur = $base . '/current';
        if (!is_dir($cur)) {
            throw new \RuntimeException('Deploy the application first');
        }
        // only the application's own binary (from the active release), with the given args, as the app user, no shell
        [$bin] = self::startParts((string)(self::readUnitExec($n) ?? './app'));
        $binPath = self::safeUnder(realpath($cur) ?: $cur, $bin);
        if (!is_file($binPath)) {
            throw new \RuntimeException('Application binary not found');
        }
        $args = [];
        foreach ((array)($a['args'] ?? []) as $arg) {
            if (preg_match('/[\0]/', (string)$arg)) {
                throw new \InvalidArgumentException('Invalid argument');
            }
            $args[] = (string)$arg;
        }
        $timeout = max(1, min(60, (int)($a['timeout'] ?? 30)));
        $cmd = [...Sys::asUser($user), '/usr/bin/env', '-C', $cur, $binPath, ...$args];
        [$c, $o, $e] = Sys::run($cmd, null, $timeout);
        return ['exit_code' => $c, 'stdout' => mb_substr($o, 0, 65536), 'stderr' => mb_substr($e, 0, 8192)];
    }

    /** The binary from the unit's ExecStart (so exec uses the same binary as the service). */
    private static function readUnitExec(string $n): ?string
    {
        if (preg_match('#/current/([^\s]+)#', (string)@file_get_contents('/etc/systemd/system/' . self::unit($n)), $m)) {
            return './' . $m[1];
        }
        return null;
    }

    // ---- shared files (read/write with a small version history) -------------

    public static function readFile(array $a): array
    {
        $n = self::name($a);
        $file = self::safeUnder(self::base($n) . '/shared', (string)($a['path'] ?? ''));
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException('File not found under shared/');
        }
        if (filesize($file) > 8388608) {
            throw new \RuntimeException('File too large (max 8 MB)');
        }
        $data = (string)file_get_contents($file);
        $binary = !mb_check_encoding($data, 'UTF-8') || str_contains($data, "\0");
        return ['path' => (string)$a['path'], 'bytes' => strlen($data), 'binary' => $binary,
            'content' => $binary ? base64_encode($data) : $data, 'encoding' => $binary ? 'base64' : 'text'];
    }

    public static function writeFile(array $a): array
    {
        $n = self::name($a);
        $user = self::user($n);
        $file = self::safeUnder(self::base($n) . '/shared', (string)($a['path'] ?? ''));
        if (is_link($file) || is_dir($file)) {
            throw new \RuntimeException('Not a writable file path');
        }
        $data = base64_decode((string)($a['b64'] ?? ''), true);
        if ($data === false) {
            throw new \InvalidArgumentException('Invalid content');
        }
        if (strlen($data) > 8388608) {
            throw new \RuntimeException('File too large (max 8 MB)');
        }
        // keep the previous version under shared/.history (last 5)
        if (is_file($file)) {
            $hist = self::base($n) . '/shared/.history';
            is_dir($hist) || mkdir($hist, 0750, true);
            $tag = str_replace('/', '_', (string)$a['path']) . '.' . gmdate('Ymd-His');
            @copy($file, "$hist/$tag");
            $old = glob("$hist/" . str_replace('/', '_', (string)$a['path']) . '.*') ?: [];
            rsort($old);
            foreach (array_slice($old, 5) as $o) {
                @unlink($o);
            }
            Sys::run(['chown', '-R', $user . ':' . $user, $hist]);
        }
        is_dir(dirname($file)) || mkdir(dirname($file), 0750, true);
        Sys::write($file, $data, 0640, $user, $user);
        return ['path' => (string)$a['path'], 'bytes' => strlen($data)];
    }

    // ---- destroy (panel only) ----------------------------------------------

    public static function destroy(array $a): array
    {
        $n = self::name($a);
        Sys::run(['systemctl', 'disable', '--now', self::unit($n)], null, 40);
        @unlink('/etc/systemd/system/' . self::unit($n));
        Sys::run(['systemctl', 'daemon-reload']);
        if (!empty($a['purge'])) {
            $base = self::base($n);
            if (str_starts_with($base, self::ROOT . '/') && is_dir($base)) {
                Sys::run(['rm', '-rf', $base]);
            }
            @unlink(self::envFile($n));
            Sys::run(['userdel', self::user($n)]);
        }
        return ['name' => $n, 'removed' => true];
    }
}
