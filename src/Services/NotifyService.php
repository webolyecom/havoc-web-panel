<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Config, Crypt, DB, Settings};

/**
 * Notification channels: e-mail (SMTP) and Telegram. Settings live in the settings table; the SMTP password and the
 * bot token are stored encrypted (Crypt) and never sent back to the browser.
 */
final class NotifyService
{
    public const SECRETS = ['notify.smtp_pass', 'notify.tg_token'];

    public const DEFAULTS = [
        'notify.email' => '0', 'notify.smtp_host' => '', 'notify.smtp_port' => '587', 'notify.smtp_secure' => 'tls', 'notify.smtp_user' => '',
        'notify.smtp_from' => '', 'notify.email_to' => '',
        'notify.telegram' => '0', 'notify.tg_chat' => '',
        'notify.lang' => 'en', 'notify.repeat' => '24', 'notify.recovery' => '1',
        'notify.r_disk' => '90', 'notify.r_mem' => '95', 'notify.r_load' => '0', 'notify.r_services' => '1', 'notify.r_ssl' => '14',
        'notify.r_update' => '1', 'notify.r_backup' => '1', 'notify.r_meili' => '0',
    ];

    public static function get(string $k): string
    {
        return Settings::get($k, self::DEFAULTS[$k] ?? '');
    }

    public static function secret(string $k): string
    {
        $v = Settings::get($k, '');
        return $v === '' ? '' : (string)Crypt::decrypt($v);
    }

    public static function setSecret(string $k, string $v): void
    {
        Settings::set($k, $v === '' ? '' : Crypt::encrypt($v));
    }

    public static function config(): array
    {
        $c = [];
        foreach (self::DEFAULTS as $k => $_) {
            $c[substr($k, 7)] = self::get($k);
        }
        $c['smtp_pass_set'] = Settings::get('notify.smtp_pass', '') !== '';
        $c['tg_token_set'] = Settings::get('notify.tg_token', '') !== '';
        return $c;
    }

    public static function emailOn(): bool
    {
        return self::get('notify.email') === '1' && self::get('notify.smtp_host') !== '' && self::recipients();
    }

    public static function telegramOn(): bool
    {
        return self::get('notify.telegram') === '1' && self::get('notify.tg_chat') !== '' && Settings::get('notify.tg_token', '') !== '';
    }

    /** @return string[] */
    public static function recipients(): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', self::get('notify.email_to')) ?: [], static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * Sends to every enabled channel (or only $only). Each attempt is logged.
     * @return array<string, ?string> channel => error (null = sent)
     */
    public static function send(string $subject, string $body, ?string $only = null): array
    {
        $out = [];
        if (($only === null && self::emailOn()) || $only === 'email') {
            $out['email'] = self::attempt('email', $subject, fn() => self::email($subject, $body));
        }
        if (($only === null && self::telegramOn()) || $only === 'telegram') {
            $out['telegram'] = self::attempt('telegram', $subject, fn() => self::telegram($subject . "\n\n" . $body));
        }
        return $out;
    }

    private static function attempt(string $channel, string $subject, callable $fn): ?string
    {
        $err = null;
        try {
            $fn();
        } catch (\Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 300);
        }
        DB::q('INSERT INTO notification_log (channel, subject, ok, error) VALUES (?, ?, ?, ?)', [$channel, mb_substr($subject, 0, 200), $err === null ? 1 : 0, $err]);
        DB::q('DELETE FROM notification_log WHERE id NOT IN (SELECT id FROM notification_log ORDER BY id DESC LIMIT 200)');
        return $err;
    }

    public static function email(string $subject, string $body): void
    {
        $to = self::recipients();
        if (!$to) {
            throw new \RuntimeException('No recipient address');
        }
        (new Mailer(['host' => self::get('notify.smtp_host'), 'port' => (int)self::get('notify.smtp_port'), 'secure' => self::get('notify.smtp_secure'),
            'user' => self::get('notify.smtp_user'), 'pass' => self::secret('notify.smtp_pass'), 'from' => self::get('notify.smtp_from'),
            'from_name' => 'Havoc WEB Panel (' . (gethostname() ?: 'server') . ')']))->send($to, $subject, $body);
    }

    public static function telegram(string $text): void
    {
        $chat = self::get('notify.tg_chat');
        if ($chat === '') {
            throw new \RuntimeException('No chat ID');
        }
        $r = self::tgApi('sendMessage', ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => 'true']);
        if (empty($r['ok'])) {
            throw new \RuntimeException('Telegram: ' . ($r['description'] ?? 'request failed'));
        }
    }

    /** Chats that recently wrote to the bot (to fill in the chat ID): the user sends /start to the bot first. */
    public static function telegramChats(): array
    {
        $r = self::tgApi('getUpdates', ['limit' => '50', 'allowed_updates' => '["message","channel_post","my_chat_member"]']);
        if (empty($r['ok'])) {
            throw new \RuntimeException('Telegram: ' . ($r['description'] ?? 'request failed'));
        }
        $chats = [];
        foreach ((array)$r['result'] as $u) {
            foreach (['message', 'channel_post', 'my_chat_member'] as $k) {
                $c = $u[$k]['chat'] ?? null;
                if (is_array($c) && isset($c['id'])) {
                    $name = trim(($c['title'] ?? '') ?: (($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
                    $chats[(string)$c['id']] = ['id' => (string)$c['id'], 'name' => $name . (isset($c['username']) ? ' (@' . $c['username'] . ')' : ''), 'type' => (string)($c['type'] ?? '')];
                }
            }
        }
        return array_values($chats);
    }

    private static function tgApi(string $method, array $params): array
    {
        $token = self::secret('notify.tg_token');
        if (!preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,64}$/D', $token)) {
            throw new \RuntimeException('Invalid bot token');
        }
        $base = rtrim((string)Config::get('notify.telegram_api', 'https://api.telegram.org'), '/');
        $ch = curl_init("$base/bot$token/$method");
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        if ($body === false) {
            throw new \RuntimeException('Telegram unreachable: ' . str_replace($token, '***', $err));   // never leak the token
        }
        return json_decode((string)$body, true) ?: [];
    }
}
