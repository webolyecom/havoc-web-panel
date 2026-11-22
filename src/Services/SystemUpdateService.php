<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\Audit;
use Havoc\Core\Ctl;
use Havoc\Core\I18n;
use Havoc\Core\Settings;

/**
 * Operating system updates (Monitoring → System updates): the list of upgradable packages (security, PHP, other), a
 * warning on the dashboard while security / PHP updates wait, a notification when new ones appear, and installing them.
 * The check (apt-get update) runs twice a day from the periodic job (usage:scan) and on demand; installing is done by
 * bin/havoc-os-upgrade (helper os.upgrade). The last result is kept in the settings table for the dashboard.
 */
final class SystemUpdateService
{
    private const CHECK_EVERY = 12 * 3600;

    /** @return array{checked:int,error:string,packages:list<array>,important:int,reboot:array} */
    public static function cached(): array
    {
        $c = json_decode(Settings::get('os.updates'), true);
        $def = ['checked' => 0, 'error' => '', 'packages' => [], 'important' => 0, 'reboot' => ['needed' => false, 'packages' => []], 'software' => []];
        return is_array($c) ? $c + $def : $def;
    }

    private static function store(array $c): void
    {
        Settings::set('os.updates', json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function check(): array
    {
        try {
            $c = Ctl::call('os.check', [], 900);
        } catch (\Throwable $e) {
            $c = ['checked' => time(), 'error' => mb_substr($e->getMessage(), 0, 300)] + self::cached();
        }
        self::store($c);
        return $c;
    }

    /** Job state and log; the list written by the helper (also at the end of an update) is taken over. */
    public static function status(): array
    {
        try {
            $s = Ctl::call('os.status');
        } catch (\Throwable $e) {
            return ['cache' => null, 'state' => '', 'finished' => '', 'log' => '', 'reboot' => ['needed' => false, 'packages' => []], 'error' => $e->getMessage()];
        }
        if (is_array($s['cache']) && (int)($s['cache']['checked'] ?? 0) > (int)self::cached()['checked']) {
            self::store($s['cache']);
        }
        return $s + ['error' => ''];
    }

    /** @param string $mode security | all | component (with $component = key of the software list) */
    public static function start(array $actor, string $mode, string $component = ''): void
    {
        $mode = in_array($mode, ['all', 'component'], true) ? $mode : 'security';
        Ctl::call('os.upgrade', ['mode' => $mode] + ($mode === 'component' ? ['component' => $component] : []));
        Audit::log('server.os_update', $mode === 'component' ? $component : $mode, ['packages' => count(self::cached()['packages'])], $actor);
    }

    /** Periodic: check twice a day; tell the admins once about each new set of security / PHP updates. */
    public static function checkDue(): void
    {
        if (time() - (int)self::cached()['checked'] < self::CHECK_EVERY) {
            return;
        }
        $c = self::check();
        $imp = array_values(array_filter($c['packages'] ?? [], static fn($p) => $p['security'] || $p['php']));
        $key = $imp ? sha1(implode("\n", array_map(static fn($p) => $p['name'] . '=' . $p['to'], $imp))) : '';
        if ($key === '' || $key === Settings::get('os.updates_notified')) {
            return;
        }
        Settings::set('os.updates_notified', $key);
        I18n::use(NotifyService::get('notify.lang'));             // the language chosen for notifications (CLI has no visitor)
        $lines = array_map(static fn($p) => '- ' . $p['name'] . ' ' . ($p['from'] !== '' ? $p['from'] . ' → ' : '') . $p['to'] . ($p['security'] ? ' (' . t('osu.t_security') . ')' : ''), array_slice($imp, 0, 30));
        $host = (string)gethostname();
        NotifyService::send(t('osu.notify_subject', ['n' => count($imp), 'host' => $host]),
            t('osu.notify_body', ['n' => count($imp)]) . "\n\n" . implode("\n", $lines) . (count($imp) > 30 ? "\n…" : ''));
    }
}
