<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * FTP (Pure-FTPd virtual users) and SFTP (OpenSSH internal-sftp chroot) accounts.
 * Both are jailed in /srv/havoc-jail/<login>/files, a *bind mount* of the chosen directory made once at creation time:
 * the jail root is root-owned and the mount is fixed to an inode, so a tenant swapping the folder for a symlink later
 * cannot make the daemon chroot anywhere else. SFTP users share the hosting account's uid (useradd -o), so files keep
 * the right owner.
 */
final class Ftp
{
    private const JAIL = '/srv/havoc-jail';
    private const SSHD = '/etc/ssh/sshd_config.d';

    private static function login(mixed $l): string
    {
        if (!is_string($l) || !preg_match('/^hv[0-9]{1,9}_[a-z0-9]{1,16}$/', $l)) {
            throw new \InvalidArgumentException('Invalid login');
        }
        return $l;
    }

    private static function proto(mixed $p): string
    {
        if (!in_array($p, ['ftp', 'sftp'], true)) {
            throw new \InvalidArgumentException('Invalid protocol');
        }
        return $p;
    }

    private static function pass(mixed $p): string
    {
        if (!is_string($p) || !preg_match('/^[\x21-\x7e]{12,64}$/', $p)) {
            throw new \InvalidArgumentException('Password must be 12-64 printable characters without spaces');
        }
        return $p;
    }

    private static function ips(mixed $v): array
    {
        $out = [];
        foreach (array_slice((array)$v, 0, 20) as $ip) {
            $ip = trim((string)$ip);
            if ($ip === '') {
                continue;
            }
            [$addr, $mask] = array_pad(explode('/', $ip, 2), 2, '32');
            if (!filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !ctype_digit($mask) || (int)$mask > 32) {
                throw new \InvalidArgumentException('Invalid IP/CIDR (IPv4 only)');
            }
            $out[] = $mask === '32' ? $addr : "$addr/$mask";
        }
        return $out;
    }

    private static function spec(array $a): array
    {
        $login = self::login($a['login'] ?? '');
        $acct  = Validator::account($a['account'] ?? '');
        if (!str_starts_with($login, $acct . '_')) {
            throw new \InvalidArgumentException('Login does not belong to this account');
        }
        $dom = Validator::domain($a['domain'] ?? '');
        $sub = trim((string)($a['subdir'] ?? ''), '/');
        $sub = $sub === '' ? '' : Validator::relPath($sub);
        $pw  = posix_getpwnam($acct);
        if (!$pw) {
            throw new \RuntimeException('Hosting account not found');
        }
        $real = realpath("/home/$acct/domains/$dom/public_html" . ($sub !== '' ? "/$sub" : ''));
        if ($real === false || !is_dir($real) || !str_starts_with($real . '/', "/home/$acct/domains/")) {
            throw new \RuntimeException('Directory does not exist inside the domain');
        }
        return ['login' => $login, 'proto' => self::proto($a['protocol'] ?? ''), 'uid' => (string)$pw['uid'], 'gid' => (string)$pw['gid'],
                'real' => $real, 'quota' => max(0, min(1000000, (int)($a['quota_mb'] ?? 0))), 'ips' => self::ips($a['ips'] ?? [])];
    }

    private static function exists(string $login): bool
    {
        return posix_getpwnam($login) !== false || Sys::run(['pure-pw', 'show', $login])[0] === 0;
    }

    public static function save(array $a, bool $create): array
    {
        $s = self::spec($a);
        $pass = null;
        if ($create) {
            if (self::exists($s['login'])) {
                throw new \RuntimeException('Login already exists');
            }
            $pass = self::pass($a['password'] ?? '');
        }
        self::mount($s['login'], $s['real']);
        try {
            $s['proto'] === 'ftp' ? self::ftpSave($s, $create, $pass) : self::sftpSave($s, $create, $pass);
        } catch (\Throwable $e) {
            if ($create) {
                self::unmount($s['login']);
            }
            throw $e;
        }
        return [];
    }

    private static function ftpSave(array $s, bool $create, ?string $pass): void
    {
        $mp = self::JAIL . '/' . $s['login'] . '/files';
        if ($create) {
            $cmd = ['pure-pw', 'useradd', $s['login'], '-u', $s['uid'], '-g', $s['gid'], '-d', $mp, '-m'];
            if ($s['quota'] > 0) {
                array_push($cmd, '-N', (string)$s['quota']);
            }
            if ($s['ips']) {
                array_push($cmd, '-r', implode(',', $s['ips']));
            }
            Sys::ok($cmd, "$pass\n$pass\n");
            return;
        }
        Sys::ok(['pure-pw', 'usermod', $s['login'], '-d', $mp, '-N', $s['quota'] > 0 ? (string)$s['quota'] : '', '-r', implode(',', $s['ips']), '-m']);
    }

    private static function sftpSave(array $s, bool $create, ?string $pass): void
    {
        self::ensureSshd();
        if ($create) {
            Sys::ok(['useradd', '-o', '-u', $s['uid'], '-g', $s['gid'], '-G', 'havoc-sftp', '-d', '/files', '-M', '-s', '/usr/sbin/nologin', $s['login']]);
            Sys::ok(['chpasswd'], $s['login'] . ':' . $pass . "\n");
        }
        $f = self::SSHD . '/60-havoc-sftp-' . $s['login'] . '.conf';
        if ($s['ips']) {
            $addr = '*,!' . implode(',!', $s['ips']);
            Sys::write($f, "# Managed by Havoc WEB Panel\nMatch User {$s['login']} Address $addr\n    DenyUsers {$s['login']}\n");
        } else {
            @unlink($f);
        }
        self::sshdReload($f);
    }

    private static function ensureSshd(): void
    {
        Sys::run(['groupadd', '-f', 'havoc-sftp']);
        $f = self::SSHD . '/50-havoc-sftp.conf';
        if (!is_file($f)) {
            Sys::write($f, "# Managed by Havoc WEB Panel\nMatch Group havoc-sftp\n    ChrootDirectory " . self::JAIL . "/%u\n    ForceCommand internal-sftp -d /files -u 022\n"
                . "    PasswordAuthentication yes\n    AuthenticationMethods password\n    AllowTcpForwarding no\n    X11Forwarding no\n    PermitTTY no\n");
            self::sshdReload($f);
        }
    }

    private static function sshdReload(string $changed): void
    {
        [$c, , $e] = Sys::run(['sshd', '-t']);
        if ($c !== 0) {
            @unlink($changed);
            throw new \RuntimeException('sshd configuration test failed: ' . trim($e));
        }
        Sys::ok(['systemctl', 'reload', 'ssh']);
    }

    public static function password(array $a): array
    {
        $login = self::login($a['login'] ?? '');
        $pass  = self::pass($a['password'] ?? '');
        if (self::proto($a['protocol'] ?? '') === 'ftp') {
            Sys::ok(['pure-pw', 'passwd', $login, '-m'], "$pass\n$pass\n");
        } else {
            Sys::ok(['chpasswd'], "$login:$pass\n");
        }
        return [];
    }

    public static function delete(array $a): array
    {
        $login = self::login($a['login'] ?? '');
        if (self::proto($a['protocol'] ?? '') === 'ftp') {
            Sys::run(['pure-pw', 'userdel', $login, '-m']);
        } else {
            if (posix_getpwnam($login)) {
                Sys::ok(['userdel', $login]);
            }
            @unlink(self::SSHD . "/60-havoc-sftp-$login.conf");
            Sys::run(['systemctl', 'reload', 'ssh']);
        }
        self::unmount($login);
        return [];
    }

    // ---- bind-mount jail ------------------------------------------------------------------------------------

    private static function mount(string $login, string $real): void
    {
        is_dir(self::JAIL) || mkdir(self::JAIL, 0755, true);
        $base = self::JAIL . "/$login";
        $mp   = "$base/files";
        is_dir($base) || mkdir($base, 0755);
        is_dir($mp) || mkdir($mp, 0755);
        chown($base, 'root');
        chown($mp, 'root');
        if (Sys::run(['mountpoint', '-q', $mp])[0] === 0) {
            Sys::run(['umount', '-l', $mp]);
        }
        Sys::ok(['mount', '--bind', $real, $mp]);
        Sys::ok(['mount', '-o', 'remount,bind,nosuid,nodev', $mp]);
        self::fstab($login, "$real $mp none bind,nosuid,nodev,nofail 0 0");
    }

    private static function unmount(string $login): void
    {
        $base = self::JAIL . "/$login";
        $mp   = "$base/files";
        if (is_dir($mp) && Sys::run(['mountpoint', '-q', $mp])[0] === 0) {
            Sys::run(['umount', '-l', $mp]);
        }
        self::fstab($login, null);
        @rmdir($mp);
        @rmdir($base);
    }

    private static function fstab(string $login, ?string $line): void
    {
        $lock = fopen('/run/havoc-fstab.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $cur = rtrim((string)file_get_contents('/etc/fstab'), "\n");
            $lines = array_values(array_filter(explode("\n", $cur), static fn($l) => !str_ends_with($l, "# havoc:$login")));
            if ($line !== null) {
                $lines[] = "$line # havoc:$login";
            }
            Sys::write('/etc/fstab', implode("\n", $lines) . "\n", 0644);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
