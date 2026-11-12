<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, Response, Scope};

/**
 * Central log management: every site's access / error / PHP error log in one place (tenant-scoped), plus server logs for admins.
 * Logs are referenced as (domain id, type) or a whitelisted server-log key; the helper maps them to fixed paths.
 */
final class LogController extends Controller
{
    private const TYPES = ['access', 'error', 'php'];
    private const SYSTEM = ['nginx-error', 'nginx-access', 'panel-php', 'panel-nginx', 'mariadb', 'mariadb-slow', 'waf'];

    private function sites(): array
    {
        return array_values(array_filter(Scope::domains($this->user()), fn($d) => $d['type'] !== 'alias'));
    }

    private function isAdmin(): bool
    {
        return $this->user()['role'] === 'admin';
    }

    /** Turn ?domain_id=&type= or ?system= into a helper reference + a label; 404 when out of scope. */
    private function ref(): array
    {
        $sys = $this->req->str('system');
        if ($sys !== '') {
            if (!$this->isAdmin() || !in_array($sys, self::SYSTEM, true)) {
                $this->abort(404);
            }
            return [['system' => $sys], $sys, null];
        }
        $d = Scope::domain($this->user(), (int)$this->req->str('domain_id', '0'));
        $type = $this->req->str('type', 'access');
        if (!$d || $d['type'] === 'alias' || !in_array($type, self::TYPES, true)) {
            $this->abort(404);
        }
        return [['domain' => $d['name'], 'type' => $type], $d['name'] . ' · ' . $type, $d];
    }

    public function index(): Response
    {
        $sites = $this->sites();
        $info = ['domains' => [], 'system' => []];
        try {
            $info = Ctl::call('log.list', ['domains' => array_column($sites, 'name'), 'system' => $this->isAdmin()], 60);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $total = 0;
        foreach ($info['domains'] as $types) {
            foreach ($types as $x) {
                $total += $x['size'] + $x['rotated_size'];
            }
        }
        return view('logs/index', ['sites' => $sites, 'info' => $info['domains'], 'system' => $info['system'], 'total' => $total, 'admin' => $this->isAdmin()]);
    }

    public function view(): Response
    {
        [$ref, $label, $d] = $this->ref();
        return view('logs/view', ['ref' => $ref, 'label' => $label, 'd' => $d, 'sites' => $this->sites(), 'admin' => $this->isAdmin(),
            'query' => http_build_query(isset($ref['system']) ? ['system' => $ref['system']] : ['domain_id' => (int)$d['id'], 'type' => $ref['type']])]);
    }

    public function poll(): Response
    {
        [$ref] = $this->ref();
        $off = $this->req->str('offset', '');
        try {
            return Response::json(Ctl::call('log.read', $ref + ['offset' => $off === '' ? null : (int)$off, 'lines' => max(50, min(2000, (int)$this->req->str('lines', '300')))], 15));
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    public function download(): Response
    {
        [$ref, $label] = $this->ref();
        set_time_limit(0);
        try {
            Ctl::stream('log.cat', $ref, static function (int $size, string $name, $pipe): void {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Length: ' . $size);
                header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
                header('X-Content-Type-Options: nosniff');
                while (!feof($pipe)) {
                    echo (string)fread($pipe, 262144);
                    flush();
                }
            });
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/logs');
        }
        Audit::log('log.download', $label);
        return new Response('');
    }

    /**
     * Clear (truncate) logs. Accepts sel[] = "<domain id>:<type>" / "system:<key>", or all=1 with types[] for every site in scope.
     * rotated=1 also deletes the rotated copies.
     */
    public function clear(): Response
    {
        $byId = [];
        foreach ($this->sites() as $s) {
            $byId[(int)$s['id']] = $s['name'];
        }
        $items = [];
        if ($this->req->post('all')) {
            $types = array_values(array_intersect((array)$this->req->post('types', []), self::TYPES)) ?: self::TYPES;
            foreach ($byId as $name) {
                foreach ($types as $t) {
                    $items[] = ['domain' => $name, 'type' => $t];
                }
            }
        }
        foreach ((array)$this->req->post('sel', []) as $v) {
            if (!is_string($v) || !preg_match('/^(\d+|system):([a-z-]+)$/', $v, $m)) {
                continue;
            }
            if ($m[1] === 'system') {
                if ($this->isAdmin() && in_array($m[2], self::SYSTEM, true)) {
                    $items[] = ['system' => $m[2]];
                }
            } elseif (isset($byId[(int)$m[1]]) && in_array($m[2], self::TYPES, true)) {
                $items[] = ['domain' => $byId[(int)$m[1]], 'type' => $m[2]];
            }
        }
        if (!$items) {
            $this->flash('error', t('logs.nothing_selected'));
            return $this->to($this->back());
        }
        try {
            $r = Ctl::call('log.clear', ['items' => $items, 'rotated' => (bool)$this->req->post('rotated')], 120);
            Audit::log('log.clear', count($items) . ' logs', ['rotated' => (bool)$this->req->post('rotated')]);
            $this->flash('success', t('logs.cleared', ['n' => $r['cleared'], 'size' => bytes((int)$r['freed'])]));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to($this->back());
    }

    private function back(): string
    {
        $b = $this->req->str('back');
        return preg_match('#^/logs(/view\?[A-Za-z0-9_=&%.-]*)?$#', $b) ? $b : '/logs';
    }

    /** The latest lines of one log type across all sites in scope, newest site first. */
    public function recent(): Response
    {
        $type = in_array($this->req->str('type'), self::TYPES, true) ? $this->req->str('type') : 'error';
        $sites = $this->sites();
        $logs = [];
        try {
            $logs = Ctl::call('log.tail_many', ['domains' => array_column($sites, 'name'), 'type' => $type, 'lines' => 25], 60)['logs'] ?? [];
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $ids = array_column($sites, 'id', 'name');
        return view('logs/recent', ['logs' => $logs, 'type' => $type, 'ids' => $ids]);
    }
}
