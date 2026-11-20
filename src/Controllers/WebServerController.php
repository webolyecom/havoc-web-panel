<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response};
use Havoc\Services\{DomainService, WebServerService};

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
    /** Server → Web server → Settings: performance, compression, browser and page cache of the active server. */
    public function settings(): Response
    {
        try {
            $tu = Ctl::call('webserver.tuning', [], 60);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/server/webserver');
        }
        $sites = DB::all("SELECT d.id, d.name, u.username, (SELECT a.app FROM apps a WHERE a.domain_id = d.id AND a.status = 'installed' LIMIT 1) AS app
            FROM domains d JOIN users u ON u.id = d.user_id WHERE d.type != 'alias' ORDER BY d.name");
        return view('server/webserver_settings', ['tu' => $tu, 'sites' => $sites, 'switching' => WebServerService::running()]);
    }

    public function settingsSave(): Response
    {
        $type = WebServerService::active();
        $in = (array)$this->req->post('v', []);
        $values = [];
        foreach ($in as $k => $v) {
            $values[(string)$k] = is_string($v) ? trim($v) : $v;
        }
        foreach (['open_file_cache', 'gzip', 'dyn_gzip', 'quic', 'cache'] as $b) {     // unchecked boxes are not sent
            $values[$b] = !empty($in[$b]) ? '1' : '0';
        }
        $sites = [];
        foreach ((array)$this->req->post('site_ttl', []) as $id => $ttl) {
            $sites[(int)$id] = !empty($this->req->post('site_on', [])[$id]) ? (int)$ttl : 0;
        }
        try {
            $r = Ctl::call('webserver.tune', ['values' => $values, 'sites' => $sites], 180);
        } catch (\Throwable $e) {
            $this->flash('error', t('wst.failed', ['e' => $e->getMessage()]));
            return $this->to('/server/webserver/settings');
        }
        // per-site parts (page cache, browser cache, upload size, PHP connections) live in the vhosts: rewrite them, PHP keeps running
        $failed = [];
        foreach (DB::all("SELECT * FROM domains WHERE type != 'alias' AND status = 'active' ORDER BY id") as $d) {
            try {
                $owner = DB::one('SELECT * FROM users WHERE id = ?', [$d['user_id']]);
                Ctl::call('domain.apply', DomainService::ctlArgs($d, $owner, ['web_only' => true]), 120);
            } catch (\Throwable $e) {
                $failed[] = $d['name'] . ': ' . $e->getMessage();
            }
        }
        Audit::log('webserver.tune', $type, ['values' => $values, 'cached_sites' => array_keys(array_filter($sites))]);
        if ($failed) {
            $this->flash('error', t('wst.sites_failed', ['list' => implode('; ', array_slice($failed, 0, 5))]));
        } else {
            $this->flash('success', t(!empty($r['restarted']) ? 'wst.saved_restart' : 'wst.saved'));
        }
        return $this->to('/server/webserver/settings');
    }

    public function purge(): Response
    {
        try {
            $r = Ctl::call('webserver.cache_purge', [], 300);
            Audit::log('webserver.cache_purge', WebServerService::active());
            $this->flash('success', t('wst.purged', ['size' => bytes((int)$r["freed"])]));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/webserver/settings#cache');
    }
}
