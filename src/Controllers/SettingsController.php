<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Auth, DB, Response, Totp};

final class SettingsController extends Controller
{
    public function index(): Response
    {
        $u = $this->user();
        if (!$u['totp_enabled'] && empty($_SESSION['totp_setup'])) {
            $_SESSION['totp_setup'] = Totp::newSecret();
        }
        $secret = $_SESSION['totp_setup'] ?? '';
        $new = $_SESSION['new_token'] ?? null;
        unset($_SESSION['new_token']);
        return view('settings', [
            'secret' => $secret,
            'otpuri' => $secret ? Totp::uri('Havoc', $u['username'], $secret) : '',
            'tokens' => DB::all('SELECT id, name, last_used, created_at FROM api_tokens WHERE user_id = ?', [$u['id']]),
            'newToken' => $new,
        ]);
    }

    public function profile(): Response
    {
        $u = $this->user();
        $theme = in_array($this->req->str('theme'), ['light', 'dark', 'system'], true) ? $this->req->str('theme') : 'system';
        $loc = isset(\Havoc\Core\I18n::SUPPORTED[$this->req->str('locale')]) ? $this->req->str('locale') : 'en';
        $email = filter_var($this->req->str('email'), FILTER_VALIDATE_EMAIL) ?: $u['email'];
        DB::update('users', (int)$u['id'], ['theme' => $theme, 'locale' => $loc, 'email' => $email]);
        $this->flash('success', t('common.saved'));
        return $this->to('/settings');
    }

    public function password(): Response
    {
        $u = $this->user();
        $new = (string)$this->req->post('new', '');
        if (!password_verify((string)$this->req->post('current', ''), $u['password']) || strlen($new) < 10) {
            $this->flash('error', t('settings.pw_invalid'));
            return $this->to('/settings');
        }
        DB::update('users', (int)$u['id'], ['password' => Auth::hash($new)]);
        Audit::log('user.password');
        $this->flash('success', t('common.saved'));
        return $this->to('/settings');
    }

    public function enable2fa(): Response
    {
        $u = $this->user();
        $secret = $_SESSION['totp_setup'] ?? '';
        if ($secret && Totp::verify($secret, $this->req->str('code'))) {
            DB::update('users', (int)$u['id'], ['totp_secret' => $secret, 'totp_enabled' => 1]);
            unset($_SESSION['totp_setup']);
            Audit::log('2fa.enable');
            $this->flash('success', t('settings.2fa_on'));
        } else {
            $this->flash('error', t('auth.invalid_code'));
        }
        return $this->to('/settings');
    }

    public function disable2fa(): Response
    {
        $u = $this->user();
        if (!password_verify((string)$this->req->post('current', ''), $u['password'])) {
            $this->flash('error', t('settings.pw_invalid'));
            return $this->to('/settings');
        }
        DB::update('users', (int)$u['id'], ['totp_secret' => null, 'totp_enabled' => 0]);
        Audit::log('2fa.disable');
        $this->flash('success', t('common.saved'));
        return $this->to('/settings');
    }

    public function tokenCreate(): Response
    {
        $u = $this->user();
        $token = 'hvc_' . bin2hex(random_bytes(24));
        DB::insert('api_tokens', ['user_id' => $u['id'], 'name' => substr($this->req->str('name', 'token'), 0, 64) ?: 'token', 'token_hash' => hash('sha256', $token)]);
        $_SESSION['new_token'] = $token;          // shown exactly once
        Audit::log('token.create');
        return $this->to('/settings');
    }

    public function tokenDelete(): Response
    {
        DB::q('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [(int)$this->param('id'), $this->user()['id']]);
        Audit::log('token.delete');
        return $this->to('/settings');
    }
}
