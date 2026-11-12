<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response, Scope};
use Havoc\Ctl\Validator;
use Havoc\Services\CronService;

final class CronController extends Controller
{
    private function job(): array
    {
        return Scope::row($this->user(), 'cron_jobs', (int)$this->param('id')) ?? $this->abort(404);
    }

    public function index(): Response
    {
        $u = $this->user();
        $ids = Scope::userIds($u);
        $sql = 'SELECT j.*, u.username, d.name AS domain FROM cron_jobs j JOIN users u ON u.id = j.user_id LEFT JOIN domains d ON d.id = j.domain_id';
        $rows = $ids === null ? DB::all("$sql ORDER BY j.id")
            : DB::all("$sql WHERE j.user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY j.id', $ids);
        $owners = $u['role'] === 'user' ? [$u] : Scope::users($u);
        if ($u['role'] === 'reseller') {
            array_unshift($owners, ['id' => $u['id'], 'username' => $u['username']]);
        }
        $domains = array_values(array_filter(Scope::domains($u), fn($d) => $d['type'] !== 'alias'));
        $run = $_SESSION['cron_run'] ?? null;
        unset($_SESSION['cron_run']);
        return view('cron/index', ['rows' => $rows, 'owners' => $owners, 'domains' => $domains, 'run' => $run,
            'tz' => CronService::timezone()->getName(), 'presets' => CronService::PRESETS]);
    }

    private function sync(int $userId): void
    {
        try {
            CronService::sync($userId);
        } catch (\Throwable $e) {
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
        }
    }

    public function store(): Response
    {
        $u = $this->user();
        $ownerId = $u['role'] === 'user' ? (int)$u['id'] : (int)$this->req->post('owner', $u['id']);
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$ownerId]);
        if (!$owner || !Scope::canManageUser($u, $owner)) {
            $this->abort(404);
        }
        try {
            $sched = Validator::cronSchedule($this->req->str('schedule'));
            $cmd   = Validator::cronCommand($this->req->str('command'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/cron');
        }
        $domainId = (int)$this->req->post('domain_id', 0) ?: null;
        if ($domainId) {
            $d = Scope::domain($u, $domainId);
            if (!$d || (int)$d['user_id'] !== $ownerId) {
                $this->abort(404);
            }
        }
        if ($owner['package_id']) {
            $max = (int)DB::q('SELECT max_cron FROM packages WHERE id = ?', [$owner['package_id']])->fetchColumn();
            if ((int)DB::q('SELECT COUNT(*) FROM cron_jobs WHERE user_id = ?', [$ownerId])->fetchColumn() >= $max) {
                $this->flash('error', t('cron.quota'));
                return $this->to('/cron');
            }
        }
        $id = DB::insert('cron_jobs', ['user_id' => $ownerId, 'domain_id' => $domainId, 'schedule' => $sched, 'command' => $cmd,
            'label' => mb_substr($this->req->str('label'), 0, 80), 'log_output' => (int)(bool)$this->req->post('log_output')]);
        Audit::log('cron.create', $owner['username'], ['schedule' => $sched]);
        $this->sync($ownerId);
        $this->flash('success', t('common.saved'));
        return $this->to('/cron');
    }

    public function update(): Response
    {
        $j = $this->job();
        $u = $this->user();
        $domainId = (int)$this->req->post('domain_id', 0) ?: null;
        if ($domainId) {
            $d = Scope::domain($u, $domainId);
            if (!$d || (int)$d['user_id'] !== (int)$j['user_id']) {
                $this->abort(404);
            }
        }
        try {
            DB::update('cron_jobs', (int)$j['id'], ['schedule' => Validator::cronSchedule($this->req->str('schedule')),
                'command' => Validator::cronCommand($this->req->str('command')), 'domain_id' => $domainId,
                'label' => mb_substr($this->req->str('label'), 0, 80), 'log_output' => (int)(bool)$this->req->post('log_output')]);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/cron');
        }
        Audit::log('cron.update', (string)$j['id']);
        $this->sync((int)$j['user_id']);
        $this->flash('success', t('common.saved'));
        return $this->to('/cron');
    }

    public function toggle(): Response
    {
        $j = $this->job();
        DB::update('cron_jobs', (int)$j['id'], ['enabled' => $j['enabled'] ? 0 : 1]);
        $this->sync((int)$j['user_id']);
        return $this->to('/cron');
    }

    public function delete(): Response
    {
        $j = $this->job();
        DB::q('DELETE FROM cron_jobs WHERE id = ?', [$j['id']]);
        Audit::log('cron.delete', (string)$j['id']);
        $this->sync((int)$j['user_id']);
        return $this->to('/cron');
    }

    /** Runs the job once right now (as the hosting user, max. 40 s) and shows its output. */
    public function run(): Response
    {
        $j = $this->job();
        $dom = $j['domain_id'] ? DB::q('SELECT name FROM domains WHERE id = ?', [$j['domain_id']])->fetchColumn() : null;
        try {
            $r = Ctl::call('cron.run', ['account' => 'hv' . $j['user_id'], 'command' => $j['command'], 'domain' => $dom ?: null], 75);
            $_SESSION['cron_run'] = ['id' => (int)$j['id'], 'label' => $j['label'] ?: $j['command']] + $r;
            Audit::log('cron.run', (string)$j['id'], ['code' => $r['code']]);
        } catch (\Throwable $e) {
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
        }
        return $this->to('/cron');
    }

    public function log(): Response
    {
        $j = $this->job();
        $data = ['exists' => false, 'mtime' => null, 'data' => ''];
        try {
            $data = Ctl::call('cron.log', ['account' => 'hv' . $j['user_id'], 'id' => (int)$j['id']], 30);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return view('cron/log', ['j' => $j, 'log' => $data, 'tz' => CronService::timezone()]);
    }

    /** Live preview for the schedule builder: description + next runs (JSON). */
    public function preview(): Response
    {
        return Response::json(CronService::explain($this->req->str('schedule'), 5));
    }
}
