<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{DB, I18n, Settings};

/**
 * Server checks for notifications, run every 5 minutes by the havoc-alerts timer (as the panel user, no root needed).
 * A problem is reported once it is seen on two checks in a row (no alarm for a service that is just restarting),
 * repeated every N hours while it lasts, and a "resolved" message follows when it is gone.
 */
final class AlertService
{
    private const CONFIRM_AFTER = 240;        // seconds: the next 5-minute check confirms a problem
    private const SSL_EVERY = 6 * 3600;

    /** @return array{sent:int, problems:int} */
    public static function check(bool $force = false): array
    {
        if (!NotifyService::emailOn() && !NotifyService::telegramOn()) {
            return ['sent' => 0, 'problems' => 0];
        }
        I18n::use(NotifyService::get('notify.lang'));
        $now = time();
        $state = [];
        foreach (DB::all('SELECT * FROM alert_state') as $r) {
            $state[$r['k']] = $r;
        }
        $problems = self::problems($state, $now);

        $new = $repeat = $resolved = [];
        $repeatAfter = (int)NotifyService::get('notify.repeat') * 3600;
        foreach ($problems as $k => $msg) {
            $s = $state[$k] ?? null;
            if (!$s) {
                DB::q('INSERT INTO alert_state (k, message, since, last_sent) VALUES (?, ?, ?, 0)', [$k, $msg, $now]);
                if ($force) {
                    $new[$k] = $msg;
                }
                continue;
            }
            DB::q('UPDATE alert_state SET message = ? WHERE k = ?', [$msg, $k]);
            if ((int)$s['last_sent'] === 0 && ($force || $now - (int)$s['since'] >= self::CONFIRM_AFTER)) {
                $new[$k] = $msg;
            } elseif ((int)$s['last_sent'] > 0 && $repeatAfter > 0 && $now - (int)$s['last_sent'] >= $repeatAfter) {
                $repeat[$k] = $msg;
            }
        }
        foreach ($state as $k => $s) {
            if (!isset($problems[$k])) {
                DB::q('DELETE FROM alert_state WHERE k = ?', [$k]);
                if ((int)$s['last_sent'] > 0 && NotifyService::get('notify.recovery') === '1') {
                    $resolved[$k] = $s['message'];
                }
            }
        }
        $once = self::oneShots();
        if (!$new && !$repeat && !$resolved && !$once) {
            return ['sent' => 0, 'problems' => count($problems)];
        }
        $host = gethostname() ?: 'server';
        $lines = [];
        foreach ($new as $m) {
            $lines[] = '⚠️ ' . $m;
        }
        foreach ($repeat as $m) {
            $lines[] = '⏰ ' . $m . ' (' . t('notify.still') . ')';
        }
        foreach ($once as $m) {
            $lines[] = 'ℹ️ ' . $m;
        }
        foreach ($resolved as $m) {
            $lines[] = '✅ ' . t('notify.resolved') . ': ' . $m;
        }
        $subject = ($new || $repeat ? t('notify.subj_problem', ['n' => count($new) + count($repeat)]) : ($resolved ? t('notify.subj_resolved') : t('notify.subj_info'))) . " – $host";
        $body = implode("\n", $lines) . "\n\n" . t('notify.footer', ['host' => $host, 'time' => date('Y-m-d H:i T')]);
        $res = NotifyService::send($subject, $body);
        if (in_array(null, $res, true)) {                       // at least one channel delivered it
            foreach (array_keys($new + $repeat) as $k) {
                DB::q('UPDATE alert_state SET last_sent = ? WHERE k = ?', [$now, $k]);
            }
        }
        return ['sent' => count($lines), 'problems' => count($problems)];
    }

    /** Current problems: key => message (in the notification language). */
    private static function problems(array $state, int $now): array
    {
        $p = [];
        $disk = (int)NotifyService::get('notify.r_disk');
        if ($disk > 0) {
            foreach (SystemStats::disks() as $d) {
                if ($d['pct'] >= $disk) {
                    $p['disk:' . $d['mount']] = t('notify.m_disk', ['mount' => $d['mount'], 'pct' => round($d['pct']), 'free' => self::size((int)$d['free'])]);
                }
            }
        }
        $memLimit = (int)NotifyService::get('notify.r_mem');
        if ($memLimit > 0) {
            $m = [];
            foreach (file('/proc/meminfo') ?: [] as $line) {
                if (preg_match('/^(\w+):\s+(\d+)/', $line, $mm)) {
                    $m[$mm[1]] = (int)$mm[2] * 1024;
                }
            }
            $total = $m['MemTotal'] ?? 0;
            $pct = $total ? 100 * ($total - ($m['MemAvailable'] ?? 0)) / $total : 0;
            if ($pct >= $memLimit) {
                $p['mem'] = t('notify.m_mem', ['pct' => round($pct), 'free' => self::size((int)($m['MemAvailable'] ?? 0))]);
            }
        }
        $loadFactor = (float)NotifyService::get('notify.r_load');
        if ($loadFactor > 0) {
            $cores = max(1, preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo')));
            $load5 = (float)(explode(' ', (string)@file_get_contents('/proc/loadavg'))[1] ?? 0);
            if ($load5 >= $loadFactor * $cores) {
                $p['load'] = t('notify.m_load', ['load' => number_format($load5, 2), 'cores' => $cores]);
            }
        }
        if (NotifyService::get('notify.r_services') === '1') {
            $p += self::services();
        }
        $sslDays = (int)NotifyService::get('notify.r_ssl');
        if ($sslDays > 0) {
            if ($now - (int)Settings::get('notify.ssl_checked', '0') >= self::SSL_EVERY) {
                Settings::set('notify.ssl_checked', (string)$now);
                $p += self::ssl($sslDays);
            } else {
                foreach ($state as $k => $s) {                   // between SSL checks the last result stands
                    if (str_starts_with($k, 'ssl:')) {
                        $p[$k] = $s['message'];
                    }
                }
            }
        }
        return $p;
    }

    /** Units that should run but do not: core services always, others only when enabled at boot. */
    private static function services(): array
    {
        $ws = ['nginx' => 'nginx', 'ols' => 'lshttpd', 'lsws' => 'lsws'][WebServerService::active()] ?? 'nginx';
        $core = [$ws => WebServerService::NAMES[WebServerService::active()] ?? 'Nginx', 'mariadb' => 'MariaDB', 'havoc-panel-nginx' => 'Panel (web)', 'havoc-panel-fpm' => 'Panel (PHP)'];
        $optional = ['pure-ftpd' => 'FTP', 'named' => 'DNS (BIND)', 'ssh' => 'SSH', 'cron' => 'Cron', 'redis-server' => 'Redis', 'memcached' => 'Memcached',
            'meilisearch' => 'Meilisearch', 'fail2ban' => 'Fail2ban'];
        [, $o] = self::run(['systemctl', 'list-unit-files', 'havoc-fpm-*', '--state=enabled', '--no-legend', '--plain']);
        $sites = [];
        if (preg_match_all('/^havoc-fpm-(\d+)\.service/m', $o, $mm)) {
            foreach ($mm[1] as $id) {
                $d = DB::one('SELECT name FROM domains WHERE id = ?', [(int)$id]);
                $optional["havoc-fpm-$id"] = 'PHP ' . ($d['name'] ?? "#$id");
                $sites["havoc-fpm-$id"] = true;
            }
        }
        $p = [];
        foreach ($core + $optional as $unit => $label) {
            [, $o] = self::run(['systemctl', 'show', $unit, '-p', 'LoadState', '-p', 'ActiveState', '-p', 'UnitFileState']);
            preg_match('/LoadState=(\S*)/', $o, $l);
            preg_match('/ActiveState=(\S*)/', $o, $a);
            preg_match('/UnitFileState=(\S*)/', $o, $f);
            if (($l[1] ?? '') !== 'loaded') {
                continue;                                          // not installed
            }
            $wanted = isset($core[$unit]) || ($f[1] ?? '') === 'enabled' || isset($sites[$unit]);
            if ($wanted && in_array($a[1] ?? '', ['failed', 'inactive'], true)) {
                $p['service:' . $unit] = t('notify.m_service', ['name' => $label, 'state' => $a[1]]);
            }
        }
        return $p;
    }

    /** Certificates of the sites with SSL that expire within $days (read over TLS from the local web server). */
    private static function ssl(int $days): array
    {
        $p = [];
        foreach (DB::all("SELECT name FROM domains WHERE ssl_mode <> 'none' AND type <> 'alias' ORDER BY id LIMIT 500") as $d) {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'peer_name' => $d['name'], 'SNI_enabled' => true]]);
            $s = @stream_socket_client('ssl://127.0.0.1:443', $en, $es, 4, STREAM_CLIENT_CONNECT, $ctx);
            if (!$s) {
                continue;
            }
            $cert = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
            fclose($s);
            $x = $cert ? openssl_x509_parse($cert) : null;
            if (!$x) {
                continue;
            }
            $names = array_map(static fn($n) => strtolower(trim(substr($n, 4))), array_filter(explode(',', (string)($x['extensions']['subjectAltName'] ?? '')), static fn($n) => str_starts_with(trim($n), 'DNS:')));
            if (!in_array(strtolower($d['name']), $names, true)) {
                continue;                                          // the server answered with another (fallback) certificate
            }
            $left = (int)floor(((int)$x['validTo_time_t'] - time()) / 86400);
            if ($left < $days) {
                $p['ssl:' . $d['name']] = $left < 0 ? t('notify.m_ssl_expired', ['domain' => $d['name']]) : t('notify.m_ssl', ['domain' => $d['name'], 'days' => $left]);
            }
        }
        return $p;
    }

    /** Events reported once: a new panel release, a failed backup. */
    private static function oneShots(): array
    {
        $sent = json_decode(Settings::get('notify.sent_once', '[]'), true) ?: [];
        $out = [];
        if (NotifyService::get('notify.r_update') === '1') {
            try {
                $rel = UpdateService::available();
            } catch (\Throwable) {
                $rel = null;
            }
            if ($rel && !in_array('update:' . $rel['version'], $sent, true)) {
                $out['update:' . $rel['version']] = t('notify.m_update', ['v' => $rel['version']]);
            }
        }
        if (NotifyService::get('notify.r_backup') === '1') {
            foreach (DB::all("SELECT b.id, b.note, d.name FROM backups b LEFT JOIN domains d ON d.id = b.domain_id WHERE b.status = 'failed' AND b.created_at >= datetime('now', '-1 day') ORDER BY b.id DESC LIMIT 20") as $b) {
                if (!in_array('backup:' . $b['id'], $sent, true)) {
                    $out['backup:' . $b['id']] = t('notify.m_backup', ['what' => $b['name'] ?: t('notify.full_backup'), 'note' => mb_substr((string)$b['note'], 0, 160)]);
                }
            }
        }
        if ($out) {
            Settings::set('notify.sent_once', json_encode(array_slice(array_merge($sent, array_keys($out)), -200)));
        }
        return $out;
    }

    private static function size(int $b): string
    {
        return $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : round($b / 1048576) . ' MB';
    }

    private static function run(array $cmd): array
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($p)) {
            return [1, ''];
        }
        $o = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($p), (string)$o];
    }
}
