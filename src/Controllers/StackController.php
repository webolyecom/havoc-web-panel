<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, Response};

/** Admin-only: Redis and Meilisearch on/off and basic settings. Both are shared, server-wide instances. */
final class StackController extends Controller
{
    private function call(string $action, array $args = [], int $timeout = 120): ?array
    {
        try {
            return Ctl::call($action, $args, $timeout);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return null;
        }
    }

    public function index(): Response
    {
        $secret = $_SESSION['new_secret'] ?? null;
        unset($_SESSION['new_secret']);
        return view('stack/index', ['redis' => $this->call('redis.status') ?? [], 'meili' => $this->call('meili.status') ?? [], 'secret' => $secret,
            'policies' => ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'volatile-lru', 'volatile-lfu', 'allkeys-random', 'volatile-random', 'volatile-ttl']]);
    }

    /** "Activate" = enable at boot + start, "Deactivate" = stop + disable. */
    public function toggle(): Response
    {
        $name = ['redis' => 'redis-server', 'meili' => 'meilisearch'][$this->param('name')] ?? $this->abort(404);
        $op = $this->param('op');
        $steps = ['activate' => ['enable', 'start'], 'deactivate' => ['stop', 'disable'], 'restart' => ['restart']][$op] ?? $this->abort(404);
        foreach ($steps as $s) {
            if ($this->call('service.control', ['name' => $name, 'op' => $s], 180) === null) {
                return $this->to('/stack');
            }
        }
        Audit::log('stack.' . $op, $name);
        $this->flash('success', t('common.done'));
        return $this->to('/stack');
    }

    public function redisSave(): Response
    {
        $args = ['maxmemory' => strtolower($this->req->str('maxmemory')), 'policy' => $this->req->str('policy', 'noeviction'),
            'appendonly' => (bool)$this->req->post('appendonly')];
        $pw = $this->req->str('password_mode');
        if ($pw === 'generate') {
            $args['password'] = bin2hex(random_bytes(16));
            $_SESSION['new_secret'] = ['label' => 'Redis requirepass', 'value' => $args['password']];
        } elseif ($pw === 'remove') {
            $args['password'] = '';
        }
        if ($this->call('redis.set', $args) !== null) {
            Audit::log('redis.set');
            $this->flash('success', t('common.saved'));
        } else {
            unset($_SESSION['new_secret']);
        }
        return $this->to('/stack');
    }

    public function meiliInstall(): Response
    {
        if ($this->call('meili.install') !== null) {
            Audit::log('meili.install');
            $this->flash('success', t('stack.meili_installing'));
        }
        return $this->to('/stack');
    }

    public function meiliUninstall(): Response
    {
        $purge = (bool)$this->req->post('purge');
        if ($this->call('meili.uninstall', ['purge' => $purge], 120) !== null) {
            Audit::log('meili.uninstall', '', ['purge' => $purge]);
            $this->flash('success', t('stack.meili_removed'));
        }
        return $this->to('/stack');
    }

    public function meiliConfigure(): Response
    {
        if ($this->call('meili.configure', ['indexing_mb' => (int)$this->req->post('indexing_mb', 0)], 90) !== null) {
            Audit::log('meili.configure');
            $this->flash('success', t('common.saved'));
        }
        return $this->to('/stack');
    }

    /** Shows the current master key once, or rotates it (rotation invalidates every API key derived from it). */
    public function meiliKey(): Response
    {
        $rotate = $this->param('op') === 'rotate';
        $r = $this->call('meili.key', ['rotate' => $rotate], 90);
        if ($r !== null) {
            $_SESSION['new_secret'] = ['label' => 'Meilisearch master key', 'value' => $r['key']];
            Audit::log($rotate ? 'meili.key.rotate' : 'meili.key.show');
        }
        return $this->to('/stack');
    }
}
