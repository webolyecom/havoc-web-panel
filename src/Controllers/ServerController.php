<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Services\SystemUpdateService;
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

    public function hostname(): Response
    {
        $host = strtolower($this->req->str('hostname'));
        try {
            $r = Ctl::call('panel.hostname_ssl', ['host' => $host, 'email' => $this->req->str('email') ?: (string)$this->user()['email']], 360);
            Audit::log('panel.hostname', $host);
            $this->flash('success', t('ph.issued', ['url' => 'https://' . $r['host'] . (!empty($r['proxy']) ? '' : ':' . $r['port'])]));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/settings#hostname');
    }

    /** Turns the Claude connector on or off for the whole panel; turning it off also ends every connection. */
    public function claude(): Response
    {
        $on = $this->req->post('enabled', '') === '1';
        \Havoc\Core\Settings::set('mcp.enabled', $on ? '1' : '0');
        if (!$on) {
            \Havoc\Services\OAuthService::revokeAll();
        }
        Audit::log('claude.connector', $on ? 'on' : 'off');
        $this->flash('success', t('common.saved'));
        return $this->to('/server/settings#claude');
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

    /** Monitoring → System updates */
    public function osUpdates(): Response
    {
        $s = SystemUpdateService::status();
        if (SystemUpdateService::cached()['checked'] === 0 && $s['state'] !== 'running') {
            SystemUpdateService::check();                         // first visit: there is nothing to show yet
        }
        return view('server/updates', ['c' => SystemUpdateService::cached(), 's' => $s]);
    }

    public function osCheck(): Response
    {
        $c = SystemUpdateService::check();
        $c['error'] !== '' ? $this->flash('error', t('osu.check_failed', ['msg' => $c['error']]))
            : $this->flash('success', t('osu.checked', ['n' => count($c['packages']), 'i' => (int)$c['important']]));
        return $this->to('/server/updates');
    }

    public function osUpgrade(): Response
    {
        try {
            SystemUpdateService::start($this->user(), (string)$this->req->post('mode', 'security'), (string)$this->req->post('component', ''));
            $this->flash('success', t('osu.started'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/updates');
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
        $ph = ['host' => '', 'port' => 0, 'issuer' => '', 'subject' => '', 'expires' => '', 'trusted' => false, 'webserver' => '', 'proxy' => false];
        try {
            $ph = Ctl::call('panel.hostname_status');
        } catch (\Throwable) {
        }
        return view('server/settings', ['st' => $st, 'via' => $via, 'host' => $this->req->host(), 'check' => $check, 'ph' => $ph,
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
