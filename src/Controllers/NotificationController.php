<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, DB, Response, Settings};
use Havoc\Services\{AlertService, NotifyService};

/** Server → Notifications: e-mail (SMTP) and Telegram channels, alert rules, test messages, recent deliveries. */
final class NotificationController extends Controller
{
    public function index(): Response
    {
        $chats = $_SESSION['tg_chats'] ?? null;
        unset($_SESSION['tg_chats']);
        return view('server/notifications', ['c' => NotifyService::config(), 'chats' => $chats,
            'log' => DB::all('SELECT * FROM notification_log ORDER BY id DESC LIMIT 15'),
            'open' => DB::all('SELECT * FROM alert_state WHERE last_sent > 0 ORDER BY since')]);
    }

    public function save(): Response
    {
        $r = $this->req;
        $in = [
            'notify.email' => $r->post('email') ? '1' : '0',
            'notify.smtp_host' => strtolower($r->str('smtp_host')),
            'notify.smtp_port' => (string)(int)$r->post('smtp_port', 587),
            'notify.smtp_secure' => $r->str('smtp_secure', 'tls'),
            'notify.smtp_user' => $r->str('smtp_user'),
            'notify.smtp_from' => $r->str('smtp_from'),
            'notify.email_to' => implode(', ', array_filter(preg_split('/[\s,;]+/', $r->str('email_to')) ?: [], 'strlen')),
            'notify.telegram' => $r->post('telegram') ? '1' : '0',
            'notify.tg_chat' => $r->str('tg_chat'),
            'notify.lang' => $r->str('lang', 'en'),
            'notify.repeat' => (string)(int)$r->post('repeat', 24),
            'notify.recovery' => $r->post('recovery') ? '1' : '0',
            'notify.r_disk' => (string)(int)$r->post('r_disk', 0),
            'notify.r_mem' => (string)(int)$r->post('r_mem', 0),
            'notify.r_load' => (string)round((float)$r->post('r_load', 0), 1),
            'notify.r_services' => $r->post('r_services') ? '1' : '0',
            'notify.r_ssl' => (string)(int)$r->post('r_ssl', 0),
            'notify.r_meili' => (string)max(0, min(1048576, (int)$r->post('r_meili', 0))),
            'notify.r_update' => $r->post('r_update') ? '1' : '0',
            'notify.r_backup' => $r->post('r_backup') ? '1' : '0',
        ];
        $err = null;
        $host = $in['notify.smtp_host'];
        $port = (int)$in['notify.smtp_port'];
        $to = array_filter(preg_split('/[\s,;]+/', $in['notify.email_to']) ?: [], 'strlen');
        if ($host !== '' && !preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/D', $host) && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $err = t('notify.e_host');
        } elseif ($port < 1 || $port > 65535 || !in_array($in['notify.smtp_secure'], ['tls', 'ssl', 'none'], true)) {
            $err = t('notify.e_port');
        } elseif ($in['notify.smtp_from'] !== '' && !filter_var($in['notify.smtp_from'], FILTER_VALIDATE_EMAIL)) {
            $err = t('notify.e_from');
        } elseif (array_filter($to, static fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL)) || count($to) > 10) {
            $err = t('notify.e_to');
        } elseif ($in['notify.email'] === '1' && ($host === '' || !$to || $in['notify.smtp_from'] === '')) {
            $err = t('notify.e_email_incomplete');
        } elseif ($in['notify.tg_chat'] !== '' && !preg_match('/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/D', $in['notify.tg_chat'])) {
            $err = t('notify.e_chat');
        } elseif (!isset(\Havoc\Core\I18n::SUPPORTED[$in['notify.lang']])) {
            $err = t('notify.e_lang');
        } elseif ((int)$in['notify.r_disk'] < 0 || (int)$in['notify.r_disk'] > 99 || (int)$in['notify.r_mem'] < 0 || (int)$in['notify.r_mem'] > 99
            || (float)$in['notify.r_load'] < 0 || (float)$in['notify.r_load'] > 50 || (int)$in['notify.r_ssl'] < 0 || (int)$in['notify.r_ssl'] > 60
            || (int)$in['notify.repeat'] < 0 || (int)$in['notify.repeat'] > 720) {
            $err = t('notify.e_rules');
        }
        $token = trim((string)$r->post('tg_token', ''));
        if (!$err && $token !== '' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,64}$/D', $token)) {
            $err = t('notify.e_token');
        }
        if (!$err && $in['notify.telegram'] === '1' && ($in['notify.tg_chat'] === '' || ($token === '' && Settings::get('notify.tg_token', '') === ''))) {
            $err = t('notify.e_tg_incomplete');
        }
        if ($err) {
            $this->flash('error', $err);
            return $this->to('/server/notifications');
        }
        foreach ($in as $k => $v) {
            Settings::set($k, $v);
        }
        $pass = (string)$r->post('smtp_pass', '');
        if ($pass !== '') {
            NotifyService::setSecret('notify.smtp_pass', $pass);
        } elseif ($r->post('smtp_pass_clear')) {
            NotifyService::setSecret('notify.smtp_pass', '');
        }
        if ($token !== '') {
            NotifyService::setSecret('notify.tg_token', $token);
        }
        Audit::log('notify.settings');
        $this->flash('success', t('common.saved'));
        return $this->to('/server/notifications');
    }

    public function test(): Response
    {
        $ch = $this->param('channel');
        if ($ch === 'check') {
            $r = AlertService::check(true);
            $this->flash('success', t('notify.checked', ['n' => $r['problems']]));
            return $this->to('/server/notifications');
        }
        in_array($ch, ['email', 'telegram'], true) || $this->abort(404);
        \Havoc\Core\I18n::use(NotifyService::get('notify.lang'));
        $host = gethostname() ?: 'server';
        $res = NotifyService::send(t('notify.test_subject') . " – $host", t('notify.test_body', ['host' => $host]), $ch);
        \Havoc\Core\I18n::boot(\Havoc\Core\Auth::user());
        $e = array_key_exists($ch, $res) ? $res[$ch] : t('notify.e_not_configured');   // null = sent
        $e === null ? $this->flash('success', t('notify.test_ok')) : $this->flash('error', t('notify.test_failed', ['e' => $e]));
        return $this->to('/server/notifications');
    }

    /** Lists the chats that wrote to the bot, so the chat ID does not have to be looked up by hand. */
    public function telegramChats(): Response
    {
        $token = trim((string)$this->req->post('tg_token', ''));
        if ($token !== '') {
            if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,64}$/D', $token)) {
                $this->flash('error', t('notify.e_token'));
                return $this->to('/server/notifications');
            }
            NotifyService::setSecret('notify.tg_token', $token);
        }
        try {
            $_SESSION['tg_chats'] = NotifyService::telegramChats();
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/server/notifications#telegram');
    }
}
