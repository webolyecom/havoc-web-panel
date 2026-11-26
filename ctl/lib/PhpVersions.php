<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * PHP versions of the server (Configuration → PHP versions): which are installed, which can be installed (sury.org /
 * Debian packages), and installing or removing one. Both run as the system update job (bin/havoc-os-upgrade
 * php-install|php-remove <version>, unit havoc-os-update, same log). The version the panel itself runs on and the
 * command-line default (`php`, used by cron jobs and WP-CLI) are never removed; the panel also refuses versions in use.
 */
final class PhpVersions
{
    public static function panelVersion(): string
    {
        $u = (string)@file_get_contents('/etc/systemd/system/havoc-panel-fpm.service');
        return preg_match('#/usr/sbin/php-fpm(\d\.\d)#', $u, $m) ? $m[1] : '';
    }

    public static function cliVersion(): string
    {
        $t = (string)@realpath('/usr/bin/php');
        return preg_match('#php(\d\.\d)$#', $t, $m) ? $m[1] : '';
    }

    public static function overview(): array
    {
        $installed = [];
        [, $o] = Sys::run(['dpkg-query', '-W', '-f', '${Package} ${Version} ${db:Status-Abbrev}\n', 'php*-fpm'], null, 30);
        foreach (explode("\n", (string)$o) as $l) {
            if (preg_match('/^php(\d\.\d)-fpm (\S+) ii/', trim($l), $m) && in_array($m[1], Validator::PHP_VERSIONS, true)) {
                $installed[$m[1]] = ['version' => $m[1], 'package' => preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $m[2]), 'full' => $m[2]];
            }
        }
        ksort($installed, SORT_NATURAL);
        $available = [];
        [, $o] = Sys::run(['apt-cache', 'pkgnames', 'php'], null, 60);
        foreach (explode("\n", (string)$o) as $n) {
            if (preg_match('/^php(\d\.\d)-fpm$/', trim($n), $m) && in_array($m[1], Validator::PHP_VERSIONS, true) && !isset($installed[$m[1]])) {
                $available[] = $m[1];
            }
        }
        sort($available, SORT_NATURAL);
        return ['installed' => array_values($installed), 'available' => array_values(array_unique($available)),
            'panel' => self::panelVersion(), 'cli' => self::cliVersion()];
    }

    public static function install(array $a): array
    {
        $v = Validator::php($a['version'] ?? '');
        if (is_file("/usr/sbin/php-fpm$v")) {
            throw new \RuntimeException("PHP $v is already installed");
        }
        return self::start('php-install', $v);
    }

    public static function remove(array $a): array
    {
        $v = Validator::php($a['version'] ?? '');
        if (!is_file("/usr/sbin/php-fpm$v")) {
            throw new \RuntimeException("PHP $v is not installed");
        }
        if ($v === self::panelVersion()) {
            throw new \RuntimeException("PHP $v runs the panel itself and cannot be removed");
        }
        if ($v === self::cliVersion()) {
            throw new \RuntimeException("PHP $v is the command-line default (cron jobs, WP-CLI) and cannot be removed");
        }
        if (count(Fpm::installedVersions()) <= 1) {
            throw new \RuntimeException('The last PHP version cannot be removed');
        }
        foreach (glob('/etc/systemd/system/havoc-fpm-*.service') ?: [] as $unit) {   // last line of defence: a site's PHP
            if (str_contains((string)file_get_contents($unit), "/usr/sbin/php-fpm$v ")) {
                throw new \RuntimeException("A site still runs on PHP $v (" . basename($unit, '.service') . ')');
            }
        }
        return self::start('php-remove', $v);
    }

    private static function start(string $mode, string $v): array
    {
        $state = trim(Sys::run(['systemctl', 'show', OsUpdates::UNIT, '-p', 'ActiveState', '--value'])[1]);
        if (in_array($state, ['active', 'activating'], true)) {
            throw new \RuntimeException('Another update job is running; wait until it has finished');
        }
        Sys::run(['systemctl', 'reset-failed', OsUpdates::UNIT]);
        Sys::ok(['systemd-run', '--no-block', '--unit=' . OsUpdates::UNIT, '--description=Havoc WEB Panel PHP ' . $v,
            '--property=TimeoutStartSec=3600', '/opt/havoc/bin/havoc-os-upgrade', $mode, $v]);
        return ['mode' => $mode, 'version' => $v];
    }
}
