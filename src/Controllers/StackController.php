<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, Response};

/** Admin-only: Redis and Meilisearch settings (pages, install / uninstall and on/off: AddonController). */
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

    /** The old Redis & Meilisearch page lives under Add-ons now. */
    public function index(): Response
    {
        return $this->to('/addons');
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
        return $this->to('/addons/redis');
    }

    public function redisKey(): Response
    {
        if (($r = $this->call('redis.key')) !== null) {
            $_SESSION['new_secret'] = ['label' => 'Redis requirepass', 'value' => $r['key']];
            Audit::log('redis.key.show');
        }
        return $this->to('/addons/redis');
    }

    public function meiliConfigure(): Response
    {
        if ($this->call('meili.configure', ['indexing_mb' => (int)$this->req->post('indexing_mb', 0)], 90) !== null) {
            Audit::log('meili.configure');
            $this->flash('success', t('common.saved'));
        }
        return $this->to('/addons/meilisearch');
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
        return $this->to('/addons/meilisearch');
    }
}
