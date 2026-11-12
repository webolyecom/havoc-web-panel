<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Crypt, Ctl, DB};

final class BackupService
{
    private const TIMEOUT = 7200;

    /** Decrypted connection settings for the helper (never sent to the browser). */
    public static function targetConfig(?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        $row = DB::one('SELECT * FROM backup_targets WHERE id = ?', [$id]);
        $json = $row ? Crypt::decrypt((string)$row['config_enc']) : null;
        $cfg = $json ? json_decode($json, true) : null;
        return is_array($cfg) ? ['type' => $row['type']] + $cfg : null;
    }

    private static function dbNames(int $userId): array
    {
        return array_column(DB::all('SELECT name FROM db_instances WHERE user_id = ? ORDER BY name', [$userId]), 'name');
    }

    /** Create the row and run the job detached (setsid) so the web request returns immediately. */
    public static function start(int $userId, ?int $targetId, string $scope, ?int $jobId = null): int
    {
        $id = DB::insert('backups', ['user_id' => $userId, 'target_id' => $targetId, 'scope' => $scope, 'status' => 'running', 'job_id' => $jobId]);
        self::spawn('backup:run', (string)$id);
        return $id;
    }

    public static function spawn(string ...$args): void
    {
        $p = proc_open(['/usr/bin/setsid', '-f', '/usr/bin/php', HAVOC_ROOT . '/bin/havoc', ...$args],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($p)) {
            proc_close($p);
        }
    }

    public static function run(int $id): void
    {
        $b = DB::one('SELECT * FROM backups WHERE id = ?', [$id]);
        if (!$b || $b['status'] !== 'running') {
            return;
        }
        try {
            $r = Ctl::call('backup.create', ['account' => 'hv' . $b['user_id'], 'db_names' => self::dbNames((int)$b['user_id']),
                'files' => $b['scope'] !== 'db', 'db' => $b['scope'] !== 'files', 'target' => self::targetConfig($b['target_id'] ? (int)$b['target_id'] : null)], self::TIMEOUT);
            DB::update('backups', $id, ['status' => 'done', 'path' => $r['file'], 'size' => $r['size'], 'remote' => (int)$r['remote']]);
            Audit::log('backup.create', $r['file'], [], ['id' => $b['user_id'], 'username' => 'system']);
        } catch (\Throwable $e) {
            DB::update('backups', $id, ['status' => 'failed', 'note' => substr($e->getMessage(), 0, 500)]);
        }
    }

    public static function restore(int $id, string $scope): void
    {
        $b = DB::one('SELECT * FROM backups WHERE id = ?', [$id]);
        if (!$b || !$b['path'] || !in_array($scope, ['full', 'files', 'db'], true)) {
            return;
        }
        DB::update('backups', $id, ['status' => 'restoring', 'note' => null]);
        try {
            $r = Ctl::call('backup.restore', ['account' => 'hv' . $b['user_id'], 'file' => $b['path'], 'files' => $scope !== 'db', 'db' => $scope !== 'files',
                'db_names' => self::dbNames((int)$b['user_id']), 'target' => self::targetConfig($b['target_id'] ? (int)$b['target_id'] : null)], self::TIMEOUT);
            DB::update('backups', $id, ['status' => 'done', 'note' => substr('restore: ' . implode('; ', $r['log'] ?? []), 0, 500)]);
        } catch (\Throwable $e) {
            DB::update('backups', $id, ['status' => 'done', 'note' => substr('restore failed: ' . $e->getMessage(), 0, 500)]);
        }
    }

    public static function remove(array $b): void
    {
        if ($b['path']) {
            try {
                Ctl::call('backup.delete', ['account' => 'hv' . $b['user_id'], 'file' => $b['path'],
                    'target' => self::targetConfig($b['target_id'] ? (int)$b['target_id'] : null)], 300);
            } catch (\Throwable $e) {
                error_log('backup delete: ' . $e->getMessage());
            }
        }
        DB::q('DELETE FROM backups WHERE id = ?', [$b['id']]);
    }

    /** Called hourly by havoc-backup.timer. */
    public static function runDue(): void
    {
        foreach (DB::all('SELECT * FROM backup_jobs WHERE enabled = 1') as $j) {
            $interval = $j['frequency'] === 'weekly' ? 7 * 86400 : 86400 - 1800;
            if ($j['last_run'] && time() - strtotime($j['last_run'] . ' UTC') < $interval) {
                continue;
            }
            DB::update('backup_jobs', (int)$j['id'], ['last_run' => gmdate('Y-m-d H:i:s')]);
            $id = DB::insert('backups', ['user_id' => $j['user_id'], 'target_id' => $j['target_id'], 'scope' => $j['scope'], 'status' => 'running', 'job_id' => $j['id']]);
            self::run($id);
            // retention: keep the newest N successful runs of this job
            $old = DB::all("SELECT * FROM backups WHERE job_id = ? AND status = 'done' ORDER BY id DESC LIMIT -1 OFFSET ?", [$j['id'], max(1, (int)$j['keep'])]);
            foreach ($old as $b) {
                self::remove($b);
            }
        }
    }
}
