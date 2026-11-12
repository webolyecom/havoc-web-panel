<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Which web server serves the sites. Nginx is always installed (the panel itself runs on its binary); OpenLiteSpeed and
 * LiteSpeed Enterprise share /usr/local/lsws, so at most one of them can be installed. The active one is recorded in
 * /etc/havoc/webserver and changed only by webserver.activate.
 */
final class WebServerFactory
{
    public const TYPES = ['nginx' => 'Nginx', 'ols' => 'OpenLiteSpeed', 'lsws' => 'LiteSpeed Enterprise'];
    public const ACTIVE_FILE = '/etc/havoc/webserver';

    public static function type(mixed $t): string
    {
        if (!is_string($t) || !isset(self::TYPES[$t])) {
            throw new \InvalidArgumentException('Unknown web server');
        }
        return $t;
    }

    public static function make(?string $type = null): WebServer
    {
        return match ($type ?? self::active()) {
            'nginx' => new NginxDriver(),
            'ols'   => new OlsDriver(),
            'lsws'  => new LswsDriver(),
            default => throw new \RuntimeException('No supported web server is installed'),
        };
    }

    public static function active(): string
    {
        $t = is_file(self::ACTIVE_FILE) ? trim((string)file_get_contents(self::ACTIVE_FILE)) : '';
        if (isset(self::TYPES[$t])) {
            return $t;
        }
        // installations from before the selection file: whatever is running, Nginx by default
        foreach (['ols', 'lsws'] as $x) {
            $d = self::make($x);
            if ($d->installed() && trim(Sys::run(['systemctl', 'is-active', $d->service()])[1]) === 'active') {
                return $x;
            }
        }
        return 'nginx';
    }

    public static function setActive(string $t): void
    {
        Sys::write(self::ACTIVE_FILE, self::type($t) . "\n", 0644);
    }

    /** Summary for the panel: active server plus the state of all three. */
    public static function detect(): array
    {
        $active = self::active();
        $all = [];
        foreach (self::TYPES as $t => $name) {
            $d = self::make($t);
            $inst = $d->installed();
            $all[$t] = ['name' => $name, 'installed' => $inst, 'version' => $inst ? $d->version() : '', 'service' => $d->service(),
                'running' => $inst && trim(Sys::run(['systemctl', 'is-active', $d->service()])[1]) === 'active'];
        }
        return ['type' => $active, 'name' => self::TYPES[$active], 'service' => $all[$active]['service'], 'version' => $all[$active]['version'], 'servers' => $all];
    }
}
