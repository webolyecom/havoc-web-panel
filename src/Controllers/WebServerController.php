<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, Response};
use Havoc\Services\WebServerService;

/** Admin: choose, install and activate the web server for the sites. */
final class WebServerController extends Controller
{
    public function index(): Response
    {
        $st = ['type' => WebServerService::active(), 'servers' => [], 'job' => '', 'job_log' => ''];
        try {
            $st = Ctl::call('webserver.detect', [], 30);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return view('server/webserver', ['st' => $st, 'sw' => WebServerService::state(), 'switching' => WebServerService::running()]);
    }

    public function install(): Response
    {
        $type = $this->param('type');
        $lic = $this->req->str('license_mode') === 'serial' ? $this->req->str('serial') : 'TRIAL';
        try {
            Ctl::call('webserver.install', ['type' => $type, 'license' => $lic], 30);
            Audit::log('webserver.install', $type);
            $this->flash('success', t('ws.installing'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/webserver');
    }

    public function activate(): Response
    {
        $type = $this->param('type');
        try {
            if ($type === WebServerService::active()) {
                throw new \RuntimeException(t('ws.already_active'));
            }
            WebServerService::start($type);
            Audit::log('webserver.activate', $type);
            $this->flash('success', t('ws.switch_started'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/webserver');
    }
}
