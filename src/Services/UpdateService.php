<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\Audit;
use Havoc\Core\Config;
use Havoc\Core\Ctl;
use Havoc\Core\Settings;

/**
 * Panel updates from GitHub Releases. The check result is cached in the settings table; the periodic job (usage:scan)
 * refreshes it every few hours and, when automatic updates are on, starts the update for a newer release.
 * Downloading and installing is done by the root script bin/havoc-update (through havocctl panel.update).
 */
final class UpdateService
{
    private const CHECK_EVERY = 6 * 3600;

    public static function current(): string
    {
        return (string)Config::get('app.version');
    }

    /** @return array{repo:string,latest:?array,checked:int,error:string} */
    public static function cached(): array
    {
        $c = json_decode(Settings::get('update.check'), true);
        return is_array($c) ? $c + ['repo' => '', 'latest' => null, 'checked' => 0, 'error' => ''] : ['repo' => '', 'latest' => null, 'checked' => 0, 'error' => ''];
    }

    public static function check(): array
    {
        $prev = self::cached();
        try {
            $r = Ctl::call('panel.update_check', [], 60);
            $c = ['repo' => (string)$r['repo'], 'latest' => $r['latest'] ?? null, 'checked' => time(), 'error' => ''];
        } catch (\Throwable $e) {
            $c = ['repo' => $prev['repo'], 'latest' => $prev['latest'], 'checked' => time(), 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
        Settings::set('update.check', json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $c;
    }

    /** Newer release than the installed version, or null. */
    public static function available(?array $c = null): ?array
    {
        $l = ($c ?? self::cached())['latest'] ?? null;
        return is_array($l) && version_compare((string)$l['version'], self::current(), '>') ? $l : null;
    }

    public static function autoEnabled(): bool
    {
        return Settings::get('update.auto') === '1';
    }

    public static function setAuto(bool $on): void
    {
        Settings::set('update.auto', $on ? '1' : '0');
    }

    public static function status(): array
    {
        try {
            return Ctl::call('panel.update_status');
        } catch (\Throwable $e) {
            return ['state' => '', 'log' => '', 'repo' => '', 'version' => self::current(), 'error' => $e->getMessage()];
        }
    }

    public static function start(?string $tag = null, bool $force = false): void
    {
        Ctl::call('panel.update', array_filter(['tag' => $tag, 'force' => $force ?: null]));
    }

    /** Periodic: refresh the check and install automatically when enabled (each release is tried only once). */
    public static function checkDue(): void
    {
        $c = self::cached();
        if (time() - (int)$c['checked'] < self::CHECK_EVERY) {
            return;
        }
        $c = self::check();
        $new = self::available($c);
        if (!$new || !self::autoEnabled() || Settings::get('update.auto_tried') === $new['version']) {
            return;
        }
        Settings::set('update.auto_tried', (string)$new['version']);
        try {
            self::start((string)$new['tag']);
            Audit::log('panel.update', 'auto ' . $new['version']);
        } catch (\Throwable $e) {
            // reported on the settings page through the update status
        }
    }
}
