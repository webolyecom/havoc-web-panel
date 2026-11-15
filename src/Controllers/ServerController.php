<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Services\UpdateService;
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

    /** Starts the update to the latest release (or the one given as "tag"). */
    public function update(): Response
    {
        $tag = (string)$this->req->post('tag', '');
        try {
            UpdateService::start($tag !== '' ? $tag : null, $this->req->post('force', '') === '1');
            Audit::log('panel.update', $tag);
            $this->flash('success', t('update.started'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/settings#update');
    }

    public function updateCheck(): Response
    {
        $c = UpdateService::check();
        if ($c['error'] !== '') {
            $this->flash('error', t('update.check_failed', ['msg' => $c['error']]));
        } elseif (UpdateService::available($c)) {
            $this->flash('success', t('update.found', ['v' => $c['latest']['version']]));
        } else {
            $this->flash('success', t('update.none'));
        }
        return $this->to('/server/settings#update');
    }

    public function updateAuto(): Response
    {
        $on = $this->req->post('auto', '') === '1';
        UpdateService::setAuto($on);
        Audit::log('panel.update.auto', $on ? 'on' : 'off');
        $this->flash('success', t('common.saved'));
        return $this->to('/server/settings#update');
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
        $check = UpdateService::cached();
        if ($check['checked'] === 0) {
            $check = UpdateService::check();                         // first visit: ask GitHub once
        }
        return view('server/settings', ['st' => $st, 'via' => $via, 'host' => $this->req->host(), 'check' => $check,
            'avail' => UpdateService::available($check), 'auto' => UpdateService::autoEnabled(), 'upd' => UpdateService::status()]);
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
