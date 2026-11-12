<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB, Settings};

/**
 * Automatic Let's Encrypt. Runs in the background (bin/havoc ssl:auto <id>) after a domain is created, an alias is added, or the
 * user clicks the Let's Encrypt button. Every name is pre-checked over HTTP first; names whose DNS does not reach this server are
 * left out (www, aliases) or, for the domain itself, the job waits and retries from the 30-minute timer with a growing delay.
 */
final class SslService
{
    private const MAX_ATTEMPTS = 12;                      // ~ 3 days with the back-off below
    private const SYSTEM = ['id' => null, 'username' => 'system'];

    public static function enabled(): bool
    {
        return Settings::get('ssl.auto', '1') === '1';
    }

    public static function autoHttps(): bool
    {
        return Settings::get('ssl.auto_https', '1') === '1';
    }

    /** Start a background issue for a vhost-owning domain (main, addon or sub). */
    public static function queue(array $d, bool $reset = true, bool $force = false): void
    {
        // automatic runs never replace a custom or Cloudflare certificate; an explicit request ($force) may
        if ($d['type'] === 'alias' || (!$force && in_array($d['ssl_mode'], ['custom', 'cloudflare'], true))) {
            return;
        }
        $f = ['ssl_auto' => 'running', 'ssl_auto_note' => null];
        if ($reset) {
            $f['ssl_auto_attempts'] = 0;
        }
        DB::update('domains', (int)$d['id'], $f);
        BackupService::spawn('ssl:auto', (string)$d['id'], $force ? 'force' : 'auto');
    }

    private static function email(array $d): string
    {
        $e = (string)DB::q('SELECT email FROM users WHERE id = ?', [$d['user_id']])->fetchColumn();
        if (!filter_var($e, FILTER_VALIDATE_EMAIL) || str_ends_with($e, '.localdomain')) {
            $e = (string)DB::q("SELECT email FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
        }
        return $e;
    }

    public static function run(int $id, bool $force = false): void
    {
        $lock = fopen(dirname((string)\Havoc\Core\Config::get('db.path')) . "/ssl-$id.lock", 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;                                           // another run for this domain is in progress
        }
        try {
            $d = DB::one('SELECT * FROM domains WHERE id = ?', [$id]);
            if (!$d || $d['type'] === 'alias' || $d['status'] !== 'active' || (!$force && in_array($d['ssl_mode'], ['custom', 'cloudflare'], true))) {
                return;
            }
            $attempt = (int)$d['ssl_auto_attempts'] + 1;
            $mark = function (string $state, ?string $note) use ($d, $attempt): void {
                DB::update('domains', (int)$d['id'], ['ssl_auto' => $state, 'ssl_auto_note' => $note === null ? null : mb_substr($note, 0, 500),
                    'ssl_auto_attempts' => $attempt, 'ssl_auto_last' => gmdate('Y-m-d H:i:s')]);
            };
            $names = DomainService::serverNames($d);
            $pre = Ctl::call('ssl.precheck', ['names' => $names], 120)['results'] ?? [];
            $main = $pre[$d['name']] ?? ['ok' => false, 'reason' => 'http_fail'];
            if (empty($main['ok'])) {
                $mark($attempt >= self::MAX_ATTEMPTS ? 'failed' : 'pending', 'dns:' . ($main['reason'] ?? 'http_fail') . (isset($main['ip']) ? ':' . $main['ip'] : ''));
                return;
            }
            $ok = array_values(array_filter($names, fn($n) => !empty($pre[$n]['ok'])));
            $skipped = array_values(array_diff($names, $ok));
            try {
                Ctl::call('ssl.letsencrypt', ['domain' => $d['name'], 'aliases' => array_values(array_diff($ok, [$d['name']])), 'email' => self::email($d)], 300);
            } catch (\Throwable $e) {
                $mark($attempt >= 3 ? 'failed' : 'pending', $e->getMessage());   // real ACME errors: few retries (rate limits)
                return;
            }
            $fields = ['ssl_mode' => 'letsencrypt'];
            if (self::autoHttps() && !$d['force_https']) {
                $fields['force_https'] = 1;
            }
            DomainService::update(self::SYSTEM, $d, $fields);
            $mark('issued', 'ok:' . implode(',', $ok) . ($skipped ? ' skip:' . implode(',', $skipped) : ''));
            Audit::log('ssl.auto', $d['name'], ['names' => $ok, 'skipped' => $skipped], self::SYSTEM);
        } catch (\Throwable $e) {
            DB::update('domains', $id, ['ssl_auto' => 'pending', 'ssl_auto_note' => mb_substr($e->getMessage(), 0, 500), 'ssl_auto_last' => gmdate('Y-m-d H:i:s')]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Called from the 30-minute timer: retry pending domains with back-off 30 min, 1 h, 2 h, ... capped at 12 h. */
    public static function retryDue(): void
    {
        foreach (DB::all("SELECT id, ssl_auto_attempts, ssl_auto_last FROM domains WHERE ssl_auto = 'pending' AND type != 'alias' AND status = 'active'") as $d) {
            $wait = min(12 * 3600, 1800 * (2 ** max(0, (int)$d['ssl_auto_attempts'] - 1)));
            if ($d['ssl_auto_last'] && time() - strtotime($d['ssl_auto_last'] . ' UTC') < $wait - 120) {
                continue;
            }
            self::run((int)$d['id']);
        }
        // a run that crashed hard leaves 'running' behind: give it back to the queue after an hour
        DB::q("UPDATE domains SET ssl_auto = 'pending' WHERE ssl_auto = 'running' AND (ssl_auto_last IS NULL OR ssl_auto_last < ?)", [gmdate('Y-m-d H:i:s', time() - 3600)]);
    }

    /** Human-readable state for the UI: [badge class, translated text]. */
    public static function describe(array $d): array
    {
        $note = (string)($d['ssl_auto_note'] ?? '');
        return match ($d['ssl_auto']) {
            'running' => ['badge-warn', t('ssl.auto_running')],
            'pending' => ['badge-warn', str_starts_with($note, 'dns:') ? t('ssl.auto_wait_dns', ['why' => t('ssl.why_' . explode(':', $note)[1])]) : t('ssl.auto_retry', ['msg' => $note])],
            'failed'  => ['badge-warn', t('ssl.auto_failed', ['msg' => str_starts_with($note, 'dns:') ? t('ssl.why_' . explode(':', $note)[1]) : $note])],
            'issued'  => ['badge-ok', t('ssl.auto_issued') . (preg_match('/skip:(\S+)/', $note, $m) ? ' ' . t('ssl.auto_skipped', ['names' => str_replace(',', ', ', $m[1])]) : '')],
            default   => ['', ''],
        };
    }
}
