<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Auth, DB, Response, Scope};

final class UserController extends Controller
{
    public function index(): Response
    {
        $u = $this->user();
        return view('users/index', [
            'users' => Scope::users($u),
            'packages' => DB::all('SELECT * FROM packages WHERE owner_id = ?', [$u['id']]),
        ]);
    }

    public function store(): Response
    {
        $actor = $this->user();
        $name  = strtolower($this->req->str('username'));
        $email = filter_var($this->req->str('email'), FILTER_VALIDATE_EMAIL);
        $pass  = (string)$this->req->post('password', '');
        $role  = $actor['role'] === 'admin' && $this->req->str('role') === 'reseller' ? 'reseller' : 'user';
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $name) || !$email || strlen($pass) < 10) {
            $this->flash('error', t('users.invalid'));
            return $this->to('/users');
        }
        if (DB::one('SELECT id FROM users WHERE username = ?', [$name])) {
            $this->flash('error', t('users.exists'));
            return $this->to('/users');
        }
        $pkg = (int)$this->req->post('package_id', 0);
        if ($pkg && !DB::one('SELECT id FROM packages WHERE id = ? AND owner_id = ?', [$pkg, $actor['id']])) {
            $pkg = 0;
        }
        DB::insert('users', ['parent_id' => $actor['id'], 'role' => $role, 'username' => $name, 'email' => $email,
            'password' => Auth::hash($pass), 'package_id' => $pkg ?: null]);
        Audit::log('user.create', $name, ['role' => $role]);
        $this->flash('success', t('common.saved'));
        return $this->to('/users');
    }

    public function status(): Response
    {
        $actor  = $this->user();
        $target = DB::one('SELECT * FROM users WHERE id = ?', [(int)$this->param('id')]);
        if (!$target || (int)$target['id'] === (int)$actor['id'] || !Scope::canManageUser($actor, $target)) {
            $this->abort(404);
        }
        $new = $target['status'] === 'active' ? 'suspended' : 'active';
        DB::update('users', (int)$target['id'], ['status' => $new]);
        Audit::log('user.' . $new, $target['username']);
        return $this->to('/users');
    }

    public function package(): Response
    {
        $actor  = $this->user();
        $target = DB::one('SELECT * FROM users WHERE id = ?', [(int)$this->param('id')]);
        if (!$target || !Scope::canManageUser($actor, $target)) {
            $this->abort(404);
        }
        $pid = (int)$this->req->post('package_id', 0);
        if ($pid && !Scope::row($actor, 'packages', $pid)) {
            $this->abort(404);
        }
        DB::update('users', (int)$target['id'], ['package_id' => $pid ?: null]);
        foreach (DB::all('SELECT * FROM domains WHERE user_id = ?', [$target['id']]) as $d) {
            try {
                \Havoc\Services\DomainService::reapply($d);     // push new RAM/CPU limits to the FPM units
            } catch (\Throwable $e) {
                error_log($e->getMessage());
            }
        }
        Audit::log('user.package', $target['username'], ['package' => $pid]);
        $this->flash('success', t('common.saved'));
        return $this->to('/users');
    }
}
