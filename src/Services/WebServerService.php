<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB, Settings};

/**
 * Switching the web server that serves the sites (Nginx / OpenLiteSpeed / LiteSpeed Enterprise). Runs in the background
 * (bin/havoc webserver:switch <type>): prepare the target, write every site for it, then activate it. The helper restarts the
 * previous server if the target does not come up, so a failed switch leaves the sites online.
 */
final class WebServerService
{
    public const NAMES = ['nginx' => 'Nginx', 'ols' => 'OpenLiteSpeed', 'lsws' => 'LiteSpeed Enterprise'];
    private const SYSTEM = ['id' => null, 'username' => 'system'];

    /** Active server as recorded by the helper (readable by the panel; no helper call needed). */
    public static function active(): string
    {
        $t = trim((string)@file_get_contents('/etc/havoc/webserver'));
        return isset(self::NAMES[$t]) ? $t : 'nginx';
    }

    public static function state(): array
    {
        $s = json_decode(Settings::get('webserver.switch', ''), true);
        return is_array($s) ? $s : [];
    }

    private static function save(array $s): void
    {
        Settings::set('webserver.switch', json_encode($s, JSON_UNESCAPED_UNICODE));
    }

    public static function running(): bool
    {
        $s = self::state();
        // a job that died without finishing is considered over after 30 minutes
        return ($s['status'] ?? '') === 'running' && time() - (int)($s['started'] ?? 0) < 1800;
    }

    public static function start(string $target): void
    {
        if (!isset(self::NAMES[$target])) {
            throw new \InvalidArgumentException('Unknown web server');
        }
        if (self::running()) {
            throw new \RuntimeException('A web server switch is already running');
        }
        self::save(['target' => $target, 'status' => 'running', 'started' => time(), 'log' => []]);
        BackupService::spawn('webserver:switch', $target);
    }

    public static function run(string $target): void
    {
        $s = self::state();
        $log = function (string $line) use (&$s): void {
            $s['log'][] = date('H:i:s') . '  ' . $line;
            self::save($s);
        };
        try {
            $log('Preparing ' . self::NAMES[$target] . '...');
            Ctl::call('webserver.prepare', ['target' => $target], 120);
            $sites = DB::all("SELECT * FROM domains WHERE type != 'alias' AND status = 'active' ORDER BY id");
            $failed = [];
            foreach ($sites as $d) {
                $owner = DB::one('SELECT * FROM users WHERE id = ?', [$d['user_id']]);
                try {
                    Ctl::call('domain.apply', DomainService::ctlArgs($d, $owner, ['target' => $target, 'web_only' => true]), 120);
                    $log('Site written: ' . $d['name']);
                } catch (\Throwable $e) {
                    $failed[] = $d['name'];
                    $log('FAILED ' . $d['name'] . ': ' . $e->getMessage());
                }
            }
            if ($failed) {
                throw new \RuntimeException(count($failed) . ' site(s) could not be written; nothing was switched');
            }
            $log('Stopping the current web server and starting ' . self::NAMES[$target] . '...');
            $r = Ctl::call('webserver.activate', ['target' => $target], 300);
            $log(self::NAMES[$target] . ' is now serving ' . count($sites) . ' site(s).');
            $s['status'] = 'done';
            $s['finished'] = time();
            self::save($s);
            Audit::log('webserver.switch', ($r['previous'] ?? '?') . ' -> ' . $target, [], self::SYSTEM);
        } catch (\Throwable $e) {
            $log('ERROR: ' . $e->getMessage());
            $s['status'] = 'failed';
            $s['finished'] = time();
            self::save($s);
            Audit::log('webserver.switch.failed', $target, ['error' => mb_substr($e->getMessage(), 0, 300)], self::SYSTEM);
        }
    }
}
