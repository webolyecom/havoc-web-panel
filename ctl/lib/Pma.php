<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * phpMyAdmin, served by the panel's own nginx under https://<panel>/phpmyadmin/.
 * - Gate: nginx auth_request against the panel session (only logged-in panel users reach it), then phpMyAdmin's own
 *   cookie login with the database user's credentials.
 * - Isolation: own PHP-FPM master running as system user "havoc-pma" (no access to the panel database or keys).
 * - Files: /opt/havoc-pma (root-owned, read-only for PHP), state in /var/lib/havoc-pma.
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

    public static function uninstall(): array
    {
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
