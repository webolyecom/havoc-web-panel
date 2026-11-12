<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Crypt, Ctl, DB, Response, Scope};
use Havoc\Services\BackupService;

final class BackupController extends Controller
{
    private const SCOPES = ['full', 'files', 'db'];

    private function ownerId(): int
    {
        $u = $this->user();
        $id = $u['role'] === 'user' ? (int)$u['id'] : (int)$this->req->post('owner', $u['id']);
        $o = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        return $o && Scope::canManageUser($u, $o) ? $id : $this->abort(404);
    }

    private function scope(): string
    {
        $s = $this->req->str('scope', 'full');
        return in_array($s, self::SCOPES, true) ? $s : 'full';
    }

    public function index(): Response
    {
        $u = $this->user();
        $ids = Scope::userIds($u);
        $in = $ids === null ? '' : ' WHERE x.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $p = $ids ?? [];
        $targets = [];
        foreach (DB::all("SELECT x.*, u.username FROM backup_targets x JOIN users u ON u.id = x.user_id$in ORDER BY x.id", $p) as $t) {
            $cfg = json_decode((string)Crypt::decrypt((string)$t['config_enc']), true) ?: [];
            $targets[] = ['id' => $t['id'], 'type' => $t['type'], 'label' => $cfg['label'] ?? $t['type'], 'username' => $t['username'], 'user_id' => $t['user_id']];
        }
        $owners = $u['role'] === 'user' ? [$u] : Scope::users($u);
        if ($u['role'] === 'reseller') {
            array_unshift($owners, ['id' => $u['id'], 'username' => $u['username']]);
        }
        return view('backups/index', [
            'backups' => DB::all("SELECT x.*, u.username FROM backups x JOIN users u ON u.id = x.user_id$in ORDER BY x.id DESC LIMIT 200", $p),
            'jobs' => DB::all("SELECT x.*, u.username FROM backup_jobs x JOIN users u ON u.id = x.user_id$in ORDER BY x.id", $p),
            'targets' => $targets, 'owners' => $owners,
        ]);
    }

    /** A target must belong to the same user the backup is for. */
    private function target(int $ownerId): ?int
    {
        $tid = (int)$this->req->post('target_id', 0);
        if (!$tid) {
            return null;
        }
        $t = Scope::row($this->user(), 'backup_targets', $tid);
        return $t && (int)$t['user_id'] === $ownerId ? $tid : $this->abort(404);
    }

    public function store(): Response
    {
        $owner = $this->ownerId();
        BackupService::start($owner, $this->target($owner), $this->scope());
        Audit::log('backup.start', (string)$owner);
        $this->flash('success', t('backups.started'));
        return $this->to('/backups');
    }

    private function backup(): array
    {
        return Scope::row($this->user(), 'backups', (int)$this->param('id')) ?? $this->abort(404);
    }

    public function restore(): Response
    {
        $b = $this->backup();
        if ($b['status'] !== 'done' || !$b['path']) {
            $this->abort(422);
        }
        BackupService::spawn('backup:restore', (string)$b['id'], $this->scope());
        DB::update('backups', (int)$b['id'], ['status' => 'restoring']);
        Audit::log('backup.restore', (string)$b['path']);
        $this->flash('success', t('backups.restoring'));
        return $this->to('/backups');
    }

    public function delete(): Response
    {
        $b = $this->backup();
        if (in_array($b['status'], ['running', 'restoring'], true)) {
            $this->abort(422);
        }
        BackupService::remove($b);
        Audit::log('backup.delete', (string)$b['path']);
        return $this->to('/backups');
    }

    public function targetStore(): Response
    {
        $owner = $this->ownerId();
        $type = $this->req->str('type');
        if (!in_array($type, ['s3', 'ftp', 'sftp'], true)) {
            $this->abort(422);
        }
        $cfg = [];
        foreach (['label', 'host', 'port', 'user', 'password', 'path', 'bucket', 'endpoint', 'region', 'access_key', 'secret_key'] as $k) {
            $cfg[$k] = substr($this->req->str($k), 0, 300);
        }
        $cfg['tls'] = (bool)$this->req->post('tls');
        try {
            Ctl::call('backup.test', ['target' => ['type' => $type] + $cfg], 90);      // validate + connect before saving
        } catch (\Throwable $e) {
            $this->flash('error', t('backups.target_failed') . ': ' . $e->getMessage());
            return $this->to('/backups');
        }
        DB::insert('backup_targets', ['user_id' => $owner, 'type' => $type, 'config_enc' => Crypt::encrypt(json_encode($cfg))]);
        Audit::log('backup.target', $type);
        $this->flash('success', t('common.saved'));
        return $this->to('/backups');
    }

    public function targetDelete(): Response
    {
        $t = Scope::row($this->user(), 'backup_targets', (int)$this->param('id')) ?? $this->abort(404);
        DB::q('DELETE FROM backup_targets WHERE id = ?', [$t['id']]);
        return $this->to('/backups');
    }

    public function jobStore(): Response
    {
        $owner = $this->ownerId();
        $freq = $this->req->str('frequency') === 'weekly' ? 'weekly' : 'daily';
        DB::insert('backup_jobs', ['user_id' => $owner, 'target_id' => $this->target($owner), 'frequency' => $freq,
            'keep' => max(1, min(60, (int)$this->req->post('keep', 7))), 'scope' => $this->scope()]);
        Audit::log('backup.job', $freq);
        $this->flash('success', t('common.saved'));
        return $this->to('/backups');
    }

    public function jobToggle(): Response
    {
        $j = Scope::row($this->user(), 'backup_jobs', (int)$this->param('id')) ?? $this->abort(404);
        DB::update('backup_jobs', (int)$j['id'], ['enabled' => $j['enabled'] ? 0 : 1]);
        return $this->to('/backups');
    }

    public function jobDelete(): Response
    {
        $j = Scope::row($this->user(), 'backup_jobs', (int)$this->param('id')) ?? $this->abort(404);
        DB::q('DELETE FROM backup_jobs WHERE id = ?', [$j['id']]);
        return $this->to('/backups');
    }
}
