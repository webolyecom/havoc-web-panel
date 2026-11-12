<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response, Scope};
use Havoc\Ctl\Validator;

/** FTP (Pure-FTPd) and SFTP accounts, each jailed to one domain folder. */
final class FtpController extends Controller
{
    private function row(): array
    {
        return Scope::row($this->user(), 'ftp_accounts', (int)$this->param('id')) ?? $this->abort(404);
    }

    public function index(): Response
    {
        $u = $this->user();
        $ids = Scope::userIds($u);
        $sql = 'SELECT f.*, u.username, d.name AS domain FROM ftp_accounts f JOIN users u ON u.id = f.user_id LEFT JOIN domains d ON d.id = f.domain_id';
        $rows = $ids === null ? DB::all("$sql ORDER BY f.login")
            : DB::all("$sql WHERE f.user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY f.login', $ids);
        $owners = $u['role'] === 'user' ? [$u] : Scope::users($u);
        if ($u['role'] === 'reseller') {
            array_unshift($owners, ['id' => $u['id'], 'username' => $u['username']]);
        }
        $new = $_SESSION['new_ftp'] ?? null;
        unset($_SESSION['new_ftp']);
        return view('ftp/index', ['rows' => $rows, 'owners' => $owners, 'new' => $new,
            'domains' => array_values(array_filter(Scope::domains($u), fn($d) => $d['type'] !== 'alias')),
            'host' => $this->req->host()]);
    }

    private function ips(): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', $this->req->str('ips')) ?: []));
    }

    private function generate(): string
    {
        return substr(strtr(base64_encode(random_bytes(24)), '+/=', '-_.'), 0, 20);
    }

    public function store(): Response
    {
        $u = $this->user();
        $ownerId = $u['role'] === 'user' ? (int)$u['id'] : (int)$this->req->post('owner', $u['id']);
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$ownerId]);
        if (!$owner || !Scope::canManageUser($u, $owner)) {
            $this->abort(404);
        }
        $d = Scope::domain($u, (int)$this->req->post('domain_id', 0));
        if (!$d || (int)$d['user_id'] !== $ownerId || $d['type'] === 'alias') {
            $this->abort(404);
        }
        $proto = $this->req->str('protocol') === 'sftp' ? 'sftp' : 'ftp';
        $short = strtolower($this->req->str('login'));
        $sub = trim($this->req->str('subdir'), '/');
        try {
            if (!preg_match('/^[a-z0-9]{1,16}$/', $short)) {
                throw new \InvalidArgumentException(t('ftp.invalid_login'));
            }
            if ($sub !== '') {
                Validator::relPath($sub);
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/ftp');
        }
        $login = 'hv' . $ownerId . '_' . $short;
        if (DB::one('SELECT id FROM ftp_accounts WHERE login = ?', [$login])) {
            $this->flash('error', t('ftp.exists'));
            return $this->to('/ftp');
        }
        if ($owner['package_id']) {
            $max = (int)DB::q('SELECT max_ftp FROM packages WHERE id = ?', [$owner['package_id']])->fetchColumn();
            if ((int)DB::q('SELECT COUNT(*) FROM ftp_accounts WHERE user_id = ?', [$ownerId])->fetchColumn() >= $max) {
                $this->flash('error', t('ftp.quota'));
                return $this->to('/ftp');
            }
        }
        $pass  = $this->req->str('password') ?: $this->generate();
        $quota = $proto === 'ftp' ? max(0, (int)$this->req->post('quota_mb', 0)) : 0;
        $ips   = $this->ips();
        try {
            Ctl::call('ftp.create', ['protocol' => $proto, 'account' => 'hv' . $ownerId, 'login' => $login, 'password' => $pass,
                'domain' => $d['name'], 'subdir' => $sub, 'quota_mb' => $quota, 'ips' => $ips], 60);
            DB::insert('ftp_accounts', ['user_id' => $ownerId, 'login' => $login, 'home' => $d['name'] . ($sub !== '' ? "/$sub" : ''), 'quota_mb' => $quota,
                'protocol' => $proto, 'domain_id' => (int)$d['id'], 'subdir' => $sub, 'allowed_ips' => implode(',', $ips)]);
            Audit::log('ftp.create', $login, ['protocol' => $proto]);
            $_SESSION['new_ftp'] = ['login' => $login, 'password' => $pass, 'protocol' => $proto];     // shown once
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/ftp');
    }

    public function update(): Response
    {
        $r = $this->row();
        $d = DB::one('SELECT * FROM domains WHERE id = ?', [$r['domain_id']]);
        $quota = $r['protocol'] === 'ftp' ? max(0, (int)$this->req->post('quota_mb', 0)) : 0;
        $ips = $this->ips();
        try {
            Ctl::call('ftp.update', ['protocol' => $r['protocol'], 'account' => 'hv' . $r['user_id'], 'login' => $r['login'],
                'domain' => $d['name'], 'subdir' => $r['subdir'], 'quota_mb' => $quota, 'ips' => $ips], 60);
            DB::update('ftp_accounts', (int)$r['id'], ['quota_mb' => $quota, 'allowed_ips' => implode(',', $ips)]);
            Audit::log('ftp.update', $r['login']);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/ftp');
    }

    public function password(): Response
    {
        $r = $this->row();
        $pass = $this->req->str('password') ?: $this->generate();
        try {
            Ctl::call('ftp.password', ['protocol' => $r['protocol'], 'login' => $r['login'], 'password' => $pass]);
            Audit::log('ftp.password', $r['login']);
            $_SESSION['new_ftp'] = ['login' => $r['login'], 'password' => $pass, 'protocol' => $r['protocol']];
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/ftp');
    }

    public function delete(): Response
    {
        $r = $this->row();
        try {
            Ctl::call('ftp.delete', ['protocol' => $r['protocol'], 'login' => $r['login']]);
            DB::q('DELETE FROM ftp_accounts WHERE id = ?', [$r['id']]);
            Audit::log('ftp.delete', $r['login']);
            $this->flash('success', t('common.deleted'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/ftp');
    }
}
