<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Services\DatabaseService;
use Havoc\Core\{Audit, Ctl, DB, Response, Scope};

final class DatabaseController extends Controller
{
    private function row(): array
    {
        return Scope::row($this->user(), 'db_instances', (int)$this->param('id')) ?? $this->abort(404);
    }

    public function index(): Response
    {
        $u = $this->user();
        $ids = Scope::userIds($u);
        $sql = 'SELECT i.*, u.username, d.name AS site FROM db_instances i JOIN users u ON u.id = i.user_id LEFT JOIN domains d ON d.id = i.domain_id';
        $rows = $ids === null ? DB::all("$sql ORDER BY i.name")
            : DB::all("$sql WHERE i.user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY i.name', $ids);
        $info = [];
        if ($rows) {
            try {
                $info = Ctl::call('db.info', ['names' => array_column($rows, 'name')])['databases'] ?? [];
            } catch (\Throwable) {
            }
        }
        $owners = $u['role'] === 'user' ? [$u] : Scope::users($u);
        if ($u['role'] === 'reseller') {
            array_unshift($owners, ['id' => $u['id'], 'username' => $u['username']]);
        }
        $pma = ['installed' => is_file('/opt/havoc-pma/index.php')];
        if ($u['role'] === 'admin') {
            try {
                $pma = Ctl::call('pma.status', [], 20);
            } catch (\Throwable) {
            }
        }
        $site = (int)$this->req->str('site', '0');
        if ($site) {
            $rows = array_values(array_filter($rows, static fn($r) => (int)$r['domain_id'] === $site));
        }
        $sites = [];
        foreach (Scope::domains($u) as $d) {
            if ($d['type'] !== 'alias') {
                $sites[] = ['id' => (int)$d['id'], 'name' => $d['name'], 'user_id' => (int)$d['user_id']];
            }
        }
        return view('databases/index', ['rows' => $rows, 'info' => $info, 'owners' => $owners, 'pma' => $pma, 'sites' => $sites, 'site' => $site,
            'new' => $_SESSION['new_db'] ?? null] + $this->clear());
    }

    private function clear(): array
    {
        unset($_SESSION['new_db']);
        return [];
    }

    public function store(): Response
    {
        $u = $this->user();
        $ownerId = $u['role'] === 'user' ? (int)$u['id'] : (int)$this->req->post('owner', $u['id']);
        $domainId = (int)$this->req->post('domain_id', 0) ?: null;
        if ($domainId !== null) {
            $dom = Scope::domain($u, $domainId) ?? $this->abort(404);
            $ownerId = (int)$dom['user_id'];                          // a site's database belongs to the site's owner
        }
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$ownerId]);
        if (!$owner || !\Havoc\Core\Scope::canManageUser($u, $owner)) {
            $this->abort(404);
        }
        try {
            $db = DatabaseService::create($u, $ownerId, $this->req->str('name'), $this->req->str('password') ?: null, $domainId);
            $_SESSION['new_db'] = ['name' => $db['name'], 'password' => $db['password']];      // shown once
        } catch (\DomainException $e) {
            $this->flash('error', str_starts_with($e->getMessage(), 'db.') ? t($e->getMessage()) : $e->getMessage());
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->back();
    }

    /** Back to the site page when the form came from there. */
    private function back(): Response
    {
        $from = (int)$this->req->post('from_domain', 0);
        return $this->to($from && Scope::domain($this->user(), $from) ? "/domains/$from/databases" : '/databases');
    }

    public function site(): Response
    {
        $r = $this->row();
        $id = (int)$this->req->post('domain_id', 0) ?: null;
        try {
            DatabaseService::assign($this->user(), $r, $id);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', str_starts_with($e->getMessage(), 'db.') ? t($e->getMessage()) : $e->getMessage());
        }
        return $this->back();
    }

    public function detect(): Response
    {
        try {
            $f = DatabaseService::detect($this->user());
            $this->flash('success', $f ? t('db.detected', ['list' => implode(', ', array_map(fn($k, $v) => "$k → $v", array_keys($f), $f))]) : t('db.detected_none'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/databases');
    }

    /** One click into phpMyAdmin for this database, no password (temporary database user, single-use ticket). */
    public function pmaOpen(): Response
    {
        $r = $this->row();
        try {
            $t = Ctl::call('pma.ticket', ['db' => $r['name']], 30);
            Audit::log('db.pma', $r['name']);
            return Response::redirect('/phpmyadmin/havoc-signon.php?t=' . $t['ticket']);
        } catch (\Throwable $e) {
            $this->flash('error', t('pma.sso_failed') . ': ' . $e->getMessage());
            return $this->back();
        }
    }

    private function generate(): string
    {
        return substr(strtr(base64_encode(random_bytes(18)), '+/=', '-_.'), 0, 20);
    }

    public function password(): Response
    {
        $r = $this->row();
        $pass = $this->req->str('password') ?: $this->generate();
        try {
            Ctl::call('db.password', ['name' => $r['name'], 'password' => $pass]);
            Audit::log('db.password', $r['name']);
            $_SESSION['new_db'] = ['name' => $r['name'], 'password' => $pass];
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/databases');
    }

    public function remote(): Response
    {
        $r = $this->row();
        $enable = !$r['remote_access'];
        try {
            Ctl::call('db.remote', ['name' => $r['name'], 'enable' => $enable]);
            DB::update('db_instances', (int)$r['id'], ['remote_access' => (int)$enable]);
            Audit::log('db.remote', $r['name'], ['enable' => $enable]);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/databases');
    }

    public function delete(): Response
    {
        $r = $this->row();
        try {
            Ctl::call('db.drop', ['name' => $r['name']]);
            DB::q('DELETE FROM db_instances WHERE id = ?', [$r['id']]);
            Audit::log('db.delete', $r['name']);
            $this->flash('success', t('common.deleted'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/databases');
    }

    /** Admin: let MariaDB listen on all interfaces so per-database remote access can work. */
    public function bind(): Response
    {
        try {
            Ctl::call('db.bind', ['enable' => (bool)$this->req->post('enable')]);
            Audit::log('db.bind', '', ['enable' => (bool)$this->req->post('enable')]);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/databases');
    }

    /** Admin: install / update phpMyAdmin (background job) or remove it. */
    public function pma(): Response
    {
        $op = $this->param('op');
        try {
            if ($op === 'install') {
                Ctl::call('pma.install');
                $this->flash('success', t('pma.installing'));
            } elseif ($op === 'uninstall') {
                Ctl::call('pma.uninstall', [], 120);
                $this->flash('success', t('pma.removed'));
            } else {
                $this->abort(404);
            }
            Audit::log('pma.' . $op);
        } catch (\Havoc\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/databases');
    }

    /** Admin: MariaDB performance settings (server-wide). */
    public function tuning(): Response
    {
        $st = ['ram_mb' => 0, 'current' => [], 'file' => [], 'presets' => []];
        try {
            $st = Ctl::call('mariadb.status', [], 30);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return view('databases/tuning', ['st' => $st, 'keys' => \Havoc\Ctl\MariaTune::KEYS]);
    }

    public function tuningSave(): Response
    {
        $in = [];
        foreach (array_keys(\Havoc\Ctl\MariaTune::KEYS) as $k) {
            $v = $this->req->post($k);
            if (is_string($v)) {
                $in[$k] = trim($v);
            }
        }
        $in['slow_query_log'] = $this->req->post('slow_query_log') ? '1' : '0';
        set_time_limit(400);
        try {
            Ctl::call('mariadb.tune', $in, 360);
            Audit::log('mariadb.tune', '', array_filter($in, fn($v) => $v !== ''));
            $this->flash('success', t('tune.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/databases/tuning');
    }
}
