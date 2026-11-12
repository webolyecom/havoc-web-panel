<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, Response};

/** Admin-only ufw front-end. The helper refuses rules that would lock the admin out. */
final class FirewallController extends Controller
{
    private function run(string $action, array $args = [], ?string $ok = 'common.saved'): void
    {
        try {
            Ctl::call($action, $args + ['actor_ip' => $this->req->ip()], 60);
            Audit::log($action, (string)($args['ip'] ?? $args['port'] ?? $args['name'] ?? $args['n'] ?? ''));
            if ($ok) {
                $this->flash('success', t($ok));
            }
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
    }

    public function index(): Response
    {
        $st = ['installed' => false, 'active' => false, 'rules' => [], 'protected' => [], 'default' => ''];
        try {
            $st = Ctl::call('fw.status');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return view('firewall/index', ['st' => $st, 'me' => $this->req->ip()]);
    }

    public function toggle(): Response
    {
        $on = $this->param('op') === 'enable';
        $this->run($on ? 'fw.enable' : 'fw.disable');
        return $this->to('/firewall');
    }

    public function allow(): Response
    {
        $this->run('fw.allow', ['port' => $this->req->str('port'), 'proto' => $this->req->str('proto', 'tcp'), 'from' => $this->req->str('from')]);
        return $this->to('/firewall');
    }

    public function ban(): Response
    {
        $this->run('fw.ban', ['ip' => $this->req->str('ip')]);
        return $this->to('/firewall');
    }

    public function whitelist(): Response
    {
        $this->run('fw.whitelist', ['ip' => $this->req->str('ip')]);
        return $this->to('/firewall');
    }

    public function preset(): Response
    {
        $this->run('fw.preset', ['name' => $this->param('name')]);
        return $this->to('/firewall');
    }

    public function delete(): Response
    {
        $this->run('fw.delete', ['n' => (int)$this->req->post('n', 0), 'raw' => (string)$this->req->post('raw', '')], 'common.deleted');
        return $this->to('/firewall');
    }
}
