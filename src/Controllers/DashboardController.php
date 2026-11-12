<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Ctl, DB, Response, Scope};

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $u = $this->user();
        $ids = Scope::userIds($u);
        $count = fn(string $t) => $ids === null
            ? (int)DB::q("SELECT COUNT(*) FROM $t")->fetchColumn()
            : (int)DB::q("SELECT COUNT(*) FROM $t WHERE user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->fetchColumn();
        $ws = null;
        if ($u['role'] === 'admin') {
            try {
                $ws = Ctl::call('webserver.detect');
            } catch (\Throwable) {
            }
        }
        $audit = $u['role'] === 'admin' ? DB::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8') : [];
        return view('dashboard', ['domains' => $count('domains'), 'users' => $u['role'] === 'user' ? 0 : count(Scope::users($u)), 'ws' => $ws, 'audit' => $audit]);
    }

    public function lang(): Response
    {
        $c = $this->param('code');
        if (isset(\Havoc\Core\I18n::SUPPORTED[$c])) {
            setcookie('havoc_lang', $c, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $this->req->isHttps(), 'httponly' => true, 'samesite' => 'Strict']);
            if ($this->userOrNull()) {
                DB::update('users', (int)$this->user()['id'], ['locale' => $c]);
            }
        }
        $ref = parse_url($_SERVER['HTTP_REFERER'] ?? '/', PHP_URL_PATH) ?: '/';
        return $this->to($ref);
    }

    private function userOrNull(): ?array
    {
        return \Havoc\Core\Auth::user();
    }
}
