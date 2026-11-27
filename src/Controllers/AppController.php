<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{DB, Response, Scope};
use Havoc\Services\{UploadService, WebAppService};

/** Applications: a long-running process (e.g. a game server) bound to a site's domain and reverse-proxied by nginx. */
final class AppController extends Controller
{
    public function index(): Response
    {
        $apps = array_map(static fn($w) => $w + ['url' => 'https://' . $w['domain'] . '/'], WebAppService::list($this->user()));
        // sites that could host a new app (manageable, active, not an alias, no app yet)
        $free = [];
        foreach (DB::all("SELECT d.id, d.name FROM domains d WHERE d.type IN ('main','addon','sub') AND d.status = 'active' AND d.id NOT IN (SELECT domain_id FROM web_apps) ORDER BY d.name") as $d) {
            if (Scope::domain($this->user(), (int)$d['id'])) {
                $free[] = $d['name'];
            }
        }
        return view('apps/index', ['apps' => $apps, 'free' => $free]);
    }

    public function show(): Response
    {
        $name = $this->param('name');
        WebAppService::require($this->user(), $name);
        try {
            $s = WebAppService::status($this->user(), $name);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/apps');
        }
        $app = WebAppService::require($this->user(), $name);
        $d = DB::one('SELECT id, name, ssl_mode FROM domains WHERE id = ?', [$app['domain_id']]);
        return view('apps/show', ['app' => $app, 's' => $s, 'domain' => $d,
            'env_keys' => WebAppService::envKeys($this->user(), $name),
            'upload' => UploadService::recent((int)$app['domain_id'])]);
    }

    public function create(): Response
    {
        try {
            $w = WebAppService::create($this->user(), [
                'name' => $this->req->str('name'), 'domain' => $this->req->str('domain'),
                'start_command' => $this->req->str('start_command'), 'health_path' => $this->req->str('health_path', '/healthz'),
                'ws_paths' => array_filter(array_map('trim', explode(',', $this->req->str('ws_paths')))),
                'shared_paths' => array_filter(array_map('trim', explode(',', $this->req->str('shared_paths')))),
            ]);
            $this->flash('success', t('app.created', ['name' => $w['name']]));
            return $this->to('/apps/' . $w['name']);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/apps');
        }
    }

    public function uploadLink(): Response
    {
        $app = WebAppService::require($this->user(), $this->param('name'));
        $d = DB::one('SELECT * FROM domains WHERE id = ?', [$app['domain_id']]);
        try {
            $r = UploadService::create($this->user(), $d, 'panel');
            return Response::json(['url' => $r['url'], 'expires' => $r['expires']]);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function deploy(): Response
    {
        $name = $this->param('name');
        try {
            $r = WebAppService::deploy($this->user(), $name, $this->req->str('upload'));
            empty($r['health'])
                ? $this->flash('error', t('app.deploy_failed') . ($r['rolled_back'] ?? false ? ' ' . t('app.rolled_back') : ''))
                : $this->flash('success', t('app.deployed', ['ref' => $r['ref'] ?? '']));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/apps/' . $name);
    }

    public function control(): Response
    {
        $name = $this->param('name');
        try {
            WebAppService::control($this->user(), $name, $this->req->str('action'));
            $this->flash('success', t('app.done'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/apps/' . $name);
    }

    public function rollback(): Response
    {
        $name = $this->param('name');
        try {
            WebAppService::rollback($this->user(), $name, $this->req->str('release') ?: null);
            $this->flash('success', t('app.done'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/apps/' . $name);
    }

    public function setEnv(): Response
    {
        $name = $this->param('name');
        $set = [];
        foreach ($this->req->post('env', []) ?: [] as $row) {
            $k = trim((string)($row['key'] ?? ''));
            if ($k !== '' && ($row['value'] ?? '') !== '') {
                $set[$k] = (string)$row['value'];
            }
        }
        $unset = array_filter(array_map('trim', (array)($this->req->post('unset', []) ?: [])));
        try {
            WebAppService::setEnv($this->user(), $name, $set, $unset, $this->req->post('restart') === '1');
            $this->flash('success', t('app.env_saved'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/apps/' . $name . '#env');
    }

    /** Live log viewer (JSON). */
    public function logs(): Response
    {
        try {
            $r = WebAppService::logs($this->user(), $this->param('name'), (int)$this->req->str('lines', '300'), null, $this->req->str('grep') ?: null);
            return Response::json($r);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function destroy(): Response
    {
        $name = $this->param('name');
        if ($this->req->str('confirm') !== $name) {
            $this->flash('error', t('app.confirm_name'));
            return $this->to('/apps/' . $name);
        }
        try {
            WebAppService::destroy($this->user(), $name);
            $this->flash('success', t('app.destroyed', ['name' => $name]));
            return $this->to('/apps');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/apps/' . $name);
        }
    }
}
