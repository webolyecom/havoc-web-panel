<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * phpMyAdmin, served by the panel's own nginx under https://<panel>/phpmyadmin/.
 * - Gate: nginx auth_request against the panel session (only logged-in panel users reach it), then phpMyAdmin's own
 *   cookie login with the database user's credentials.
 * - Isolation: own PHP-FPM master running as system user "havoc-pma" (no access to the panel database or keys).
 * - Files: /opt/havoc-pma (root-owned, read-only for PHP), state in /var/lib/havoc-pma.
 * - One-click login (server 2, "signon"): the panel asks for a ticket for one database; root creates a temporary MySQL
 *   user that can only reach that database (dropped after 2 hours) and a single-use ticket file valid for one minute.
 *   havoc-signon.php redeems the ticket into phpMyAdmin's signon session. No database password is stored anywhere.
 */
final class Pma
{
    public const DIR = '/opt/havoc-pma';
    private const NGINX = '/etc/havoc/nginx/conf.d/pma.conf';
    private const UNIT = '/etc/systemd/system/havoc-pma-fpm.service';
    private const JOB = 'havoc-pma-install';

    public static function status(): array
    {
        $job = trim(Sys::run(['systemctl', 'show', self::JOB, '-p', 'ActiveState', '--value'])[1]);
        $log = in_array($job, ['active', 'activating', 'failed'], true) ? trim(Sys::run(['journalctl', '-u', self::JOB, '--no-pager', '-n', '15', '-o', 'cat'])[1]) : '';
        $ver = '';
        foreach (glob(self::DIR . '/RELEASE-DATE-*') ?: [] as $f) {
            $ver = substr(basename($f), 13);
        }
        $active = trim(Sys::run(['systemctl', 'is-active', 'havoc-pma-fpm'])[1]);
        $php = '';
        if (is_file(self::UNIT) && preg_match('/php-fpm(\d\.\d)/', (string)file_get_contents(self::UNIT), $m)) {
            $php = $m[1];
        }
        return ['installed' => is_file(self::DIR . '/index.php') && is_file(self::NGINX), 'version' => $ver, 'php' => $php,
            'active' => $active, 'job' => $job, 'log' => $log];
    }

    public static function install(): array
    {
        $job = trim(Sys::run(['systemctl', 'show', self::JOB, '-p', 'ActiveState', '--value'])[1]);
        if (in_array($job, ['active', 'activating'], true)) {
            throw new \RuntimeException('Installation is already running');
        }
        if ($job === 'failed') {
            Sys::run(['systemctl', 'reset-failed', self::JOB]);
        }
        Sys::ok(['systemd-run', '--no-block', '--unit=' . self::JOB, '/opt/havoc/bin/havoc-pma-install']);
        return [];
    }

    private const STATE = '/var/lib/havoc-pma';
    private const TICKETS = '/var/lib/havoc-pma/tickets';
    private const USERS = '/var/lib/havoc-pma/sso-users.json';           // root only: temporary users and expiry
    private const MARK = '// havoc: one-click login (server 2)';
    private const USER_TTL = 7200;

    /** Idempotent: the signon server in config.inc.php, the redeem script and the ticket folder. */
    public static function ssoSetup(): array
    {
        $cfg = self::DIR . '/config.inc.php';
        if (!is_file($cfg)) {
            throw new \RuntimeException('phpMyAdmin is not installed');
        }
        $c = (string)file_get_contents($cfg);
        if (!str_contains($c, self::MARK)) {
            $c = rtrim($c) . "\n\n" . self::MARK . "\n"
                . "\$cfg['Servers'][2]['auth_type'] = 'signon';\n"
                . "\$cfg['Servers'][2]['host'] = 'localhost';\n"
                . "\$cfg['Servers'][2]['compress'] = false;\n"
                . "\$cfg['Servers'][2]['AllowRoot'] = false;\n"
                . "\$cfg['Servers'][2]['SignonSession'] = 'HavocPmaSSO';\n"
                . "\$cfg['Servers'][2]['SignonCookieParams'] = ['lifetime' => 0, 'path' => '/phpmyadmin/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'];\n"
                . "\$cfg['Servers'][2]['SignonURL'] = '/databases';\n"
                . "\$cfg['Servers'][2]['LogoutURL'] = '/databases';\n"
                . "\$cfg['Servers'][2]['verbose'] = 'Havoc';\n";
            Sys::write($cfg, $c, 0640, 'root', 'havoc-pma');
        }
        $script = <<<'PHP'
<?php
// Havoc WEB Panel: redeems a one-time ticket from the panel into phpMyAdmin's signon session (server 2).
declare(strict_types=1);
$t = (string)($_GET['t'] ?? '');
$file = '/var/lib/havoc-pma/tickets/' . $t . '.json';
if (!preg_match('/^[a-f0-9]{48}$/D', $t) || !is_file($file)) {
    header('Location: /databases', true, 302);
    exit;
}
$d = json_decode((string)file_get_contents($file), true);
@unlink($file);                                                    // single use
if (!is_array($d) || (int)($d['exp'] ?? 0) < time() || !preg_match('/^hv\d+_[a-z0-9_]{1,16}$/D', (string)($d['db'] ?? ''))) {
    header('Location: /databases', true, 302);
    exit;
}
session_set_cookie_params(['lifetime' => 0, 'path' => '/phpmyadmin/', 'domain' => '', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
session_name('HavocPmaSSO');
@session_start();
session_regenerate_id(true);
$_SESSION['PMA_single_signon_user'] = (string)$d['user'];
$_SESSION['PMA_single_signon_password'] = (string)$d['pass'];
$_SESSION['PMA_single_signon_host'] = 'localhost';
session_write_close();
header('Location: index.php?' . http_build_query(['route' => '/database/structure', 'server' => 2, 'db' => $d['db']]), true, 302);
PHP;
        Sys::write(self::DIR . '/havoc-signon.php', $script, 0644);
        is_dir(self::TICKETS) || mkdir(self::TICKETS, 0700, true);
        chown(self::TICKETS, 'havoc-pma');
        chgrp(self::TICKETS, 'havoc-pma');
        chmod(self::TICKETS, 0700);
        return [];
    }

    private static function sql(string $sql): string
    {
        return Sys::ok([is_file('/usr/bin/mariadb') ? '/usr/bin/mariadb' : '/usr/bin/mysql', '--batch', '-N'], $sql);
    }

    /** A single-use login for one database (the panel checked that the user may open it). */
    public static function ticket(array $a): array
    {
        $db = (string)($a['db'] ?? '');
        if (!preg_match('/^hv\d+_[a-z0-9_]{1,16}$/D', $db)) {
            throw new \InvalidArgumentException('Invalid database');
        }
        if ((int)self::sql("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$db';") !== 1) {
            throw new \RuntimeException('Database not found');
        }
        self::ssoSetup();
        self::cleanup();
        $user = 'hvpma_' . bin2hex(random_bytes(5));
        $pass = bin2hex(random_bytes(18));
        $g = str_replace('_', '\\_', $db);
        self::sql("CREATE USER '$user'@'localhost' IDENTIFIED BY '$pass';\nGRANT ALL PRIVILEGES ON `$g`.* TO '$user'@'localhost';\nFLUSH PRIVILEGES;\n");
        $list = json_decode((string)@file_get_contents(self::USERS), true) ?: [];
        $list[$user] = time() + self::USER_TTL;
        Sys::write(self::USERS, json_encode($list), 0600);
        $t = bin2hex(random_bytes(24));
        Sys::write(self::TICKETS . "/$t.json", json_encode(['user' => $user, 'pass' => $pass, 'db' => $db, 'exp' => time() + 60]), 0600, 'havoc-pma', 'havoc-pma');
        return ['ticket' => $t, 'user' => $user];
    }

    /** Drops expired temporary users and stale tickets (hourly and before every new ticket). */
    public static function cleanup(bool $all = false): array
    {
        $list = json_decode((string)@file_get_contents(self::USERS), true) ?: [];
        $n = 0;
        foreach ($list as $u => $exp) {
            if (($all || $exp < time()) && preg_match('/^hvpma_[a-f0-9]{10}$/D', (string)$u)) {
                try {
                    self::sql("DROP USER IF EXISTS '$u'@'localhost';");
                } catch (\Throwable) {
                }
                unset($list[$u]);
                $n++;
            }
        }
        if (is_dir(self::STATE)) {
            Sys::write(self::USERS, json_encode($list), 0600);
        }
        foreach (glob(self::TICKETS . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 120) {
                @unlink($f);
            }
        }
        return ['dropped' => $n];
    }

    public static function uninstall(): array
    {
        self::cleanup(true);
        @unlink(self::NGINX);
        Sys::run(['/usr/sbin/nginx', '-t', '-c', '/etc/havoc/nginx/nginx.conf']);
        Sys::run(['systemctl', 'reload', 'havoc-panel-nginx']);
        Sys::run(['systemctl', 'disable', '--now', 'havoc-pma-fpm']);
        @unlink(self::UNIT);
        Sys::run(['systemctl', 'daemon-reload']);
        Sys::run(['rm', '-rf', self::DIR, '/var/lib/havoc-pma', '/etc/havoc/pma-fpm.conf']);
        Sys::run(['userdel', 'havoc-pma']);
        return [];
    }
}
