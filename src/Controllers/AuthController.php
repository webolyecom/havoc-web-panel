<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Auth, Config, DB, RateLimiter, Response, Totp};

final class AuthController extends Controller
{
    public function loginForm(): Response
    {
        return view('auth/login', [], 'auth/layout');
    }

    public function login(): Response
    {
        [$status] = Auth::attempt($this->req->str('username'), (string)$this->req->post('password', ''), $this->req->ip());
        return match ($status) {
            'ok'     => $this->to('/'),
            '2fa'    => $this->to('/login/2fa'),
            'locked' => $this->fail(t('auth.locked', ['min' => Config::get('security.lockout_minutes')])),
            default  => $this->fail(t('auth.invalid')),
        };
    }

    private function fail(string $msg): Response
    {
        $this->flash('error', $msg);
        return $this->to('/login');
    }

    public function twoFaForm(): Response
    {
        if (empty($_SESSION['pending_2fa'])) {
            return $this->to('/login');
        }
        return view('auth/2fa', [], 'auth/layout');
    }

    public function twoFa(): Response
    {
        $id = $_SESSION['pending_2fa'] ?? null;
        if (!$id || time() - ($_SESSION['pending_2fa_at'] ?? 0) > 300) {
            return $this->to('/login');
        }
        $u = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        $ip = $this->req->ip();
        if (!$u || RateLimiter::locked($ip, $u['username'])) {
            return $this->fail(t('auth.locked', ['min' => Config::get('security.lockout_minutes')]));
        }
        if (Totp::verify((string)$u['totp_secret'], $this->req->str('code'))) {
            Auth::login($u, $ip);
            return $this->to('/');
        }
        RateLimiter::hit($ip, $u['username']);
        $this->flash('error', t('auth.invalid_code'));
        return $this->to('/login/2fa');
    }

    public function logout(): Response
    {
        Auth::logout();
        return $this->to('/login');
    }
}
