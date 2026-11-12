<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, Response};
use Havoc\Services\SystemStats;

final class ServerController extends Controller
{
    public function monitor(): Response
    {
        return view('server/monitor', ['stats' => SystemStats::snapshot()]);
    }

    public function stats(): Response
    {
        return Response::json(SystemStats::snapshot());
    }

    public function services(): Response
    {
        $list = [];
        try {
            $list = Ctl::call('service.list')['services'];
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return view('server/services', ['services' => $list]);
    }

    public function control(): Response
    {
        try {
            Ctl::call('service.control', ['name' => $this->param('name'), 'op' => $this->param('op')]);
            Audit::log('service.' . $this->param('op'), $this->param('name'));
            $this->flash('success', t('common.done'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/services');
    }

    public function update(): Response
    {
        try {
            Ctl::call('panel.update');
            Audit::log('panel.update');
            $this->flash('success', t('server.updating'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/');
    }

    /** Panel settings: HTTPS port (two-step change with automatic revert) and panel update. */
    public function settings(): Response
    {
        $st = ['ports' => [], 'pending' => null];
        try {
            $st = Ctl::call('panel.port_status');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $via = (int)($_SERVER['SERVER_PORT'] ?? 0);
        return view('server/settings', ['st' => $st, 'via' => $via, 'host' => $this->req->host()]);
    }

    public function portChange(): Response
    {
        try {
            $r = Ctl::call('panel.port_change', ['port' => (int)$this->req->post('port', 0)], 60);
            Audit::log('panel.port.change', $r['old'] . ' -> ' . $r['new']);
            $this->flash('success', t('panel.port_pending', ['port' => $r['new']]));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/settings');
    }

    public function portConfirm(): Response
    {
        try {
            $r = Ctl::call('panel.port_confirm', ['via_port' => (int)($_SERVER['SERVER_PORT'] ?? 0)], 60);
            Audit::log('panel.port.confirm', (string)($r['port'] ?? ''));
            $this->flash('success', t('panel.port_done', ['port' => $r['port'] ?? '']));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/settings');
    }

    public function portRevert(): Response
    {
        try {
            Ctl::call('panel.port_revert', [], 60);
            Audit::log('panel.port.revert');
            $this->flash('success', t('panel.port_reverted'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/settings');
    }
}
