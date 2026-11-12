<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB, Settings};

/**
 * Periodic scan (havoc-usage.timer, every 30 min): disk + inodes + database size per account, traffic per domain from the
 * access logs, then quota evaluation. With "enforce" on, accounts over quota get their sites answered with 503 until they are
 * back under the limit. CPU and RAM limits need no scan: they are cgroup limits on each domain's PHP-FPM unit.
 */
final class UsageService
{
    private const WARN = 0.8;

    public static function scan(): void
    {
        $users = DB::all('SELECT DISTINCT user_id FROM domains');
        $res = $users ? Ctl::call('usage.scan', ['accounts' => array_map(fn($r) => 'hv' . $r['user_id'], $users)], 7200)['usage'] ?? [] : [];
        foreach ($users as $r) {
            $uid = (int)$r['user_id'];
            $m = $res['hv' . $uid] ?? null;
            if (!$m) {
                continue;
            }
            $db = 0;
            $names = array_column(DB::all('SELECT name FROM db_instances WHERE user_id = ?', [$uid]), 'name');
            if ($names) {
                try {
                    foreach (Ctl::call('db.info', ['names' => $names])['databases'] ?? [] as $d) {
                        $db += (int)$d['size'];
                    }
                } catch (\Throwable) {
                }
            }
            DB::q('INSERT INTO usage_accounts (user_id, disk_bytes, db_bytes, inodes, updated_at) VALUES (?,?,?,?,?)
                   ON CONFLICT(user_id) DO UPDATE SET disk_bytes = excluded.disk_bytes, db_bytes = excluded.db_bytes, inodes = excluded.inodes, updated_at = excluded.updated_at',
                [$uid, $m['disk'], $db, $m['inodes'], gmdate('Y-m-d H:i:s')]);
        }
        self::traffic();
        DB::q('DELETE FROM traffic_daily WHERE day < ?', [gmdate('Y-m-d', time() - 400 * 86400)]);
        self::evaluate();
    }

    private static function traffic(): void
    {
        $doms = DB::all("SELECT d.id, d.name, COALESCE(s.pos, 0) AS pos FROM domains d LEFT JOIN traffic_state s ON s.domain_id = d.id WHERE d.type != 'alias' AND d.logs_enabled = 1 AND d.status = 'active'");
        foreach (array_chunk($doms, 100) as $batch) {
            $r = Ctl::call('traffic.scan', ['items' => array_map(fn($d) => ['domain' => $d['name'], 'offset' => (int)$d['pos']], $batch)], 900)['traffic'] ?? [];
            foreach ($batch as $d) {
                $x = $r[$d['name']] ?? null;
                if (!$x) {
                    continue;
                }
                DB::pdo()->beginTransaction();
                foreach ($x['days'] as $day => $v) {
                    DB::q('INSERT INTO traffic_daily (domain_id, day, bytes, hits, s2, s3, s4, s5) VALUES (?,?,?,?,?,?,?,?)
                           ON CONFLICT(domain_id, day) DO UPDATE SET bytes = bytes + excluded.bytes, hits = hits + excluded.hits,
                           s2 = s2 + excluded.s2, s3 = s3 + excluded.s3, s4 = s4 + excluded.s4, s5 = s5 + excluded.s5',
                        [$d['id'], $day, $v['bytes'], $v['hits'], $v['s2'], $v['s3'], $v['s4'], $v['s5']]);
                }
                DB::q('INSERT INTO traffic_state (domain_id, pos) VALUES (?, ?) ON CONFLICT(domain_id) DO UPDATE SET pos = excluded.pos', [$d['id'], (int)$x['offset']]);
                DB::pdo()->commit();
            }
        }
    }

    public static function monthBytes(int $userId): int
    {
        return (int)DB::q("SELECT COALESCE(SUM(t.bytes), 0) FROM traffic_daily t JOIN domains d ON d.id = t.domain_id WHERE d.user_id = ? AND t.day >= ?",
            [$userId, gmdate('Y-m-01')])->fetchColumn();
    }

    /** @return array{disk:?float,inodes:?float,bandwidth:?float} ratios used/limit (null = no limit) */
    public static function ratios(array $a, ?array $p): array
    {
        $r = fn(int $used, ?int $limit) => $limit ? $used / $limit : null;
        return ['disk' => $r((int)$a['disk_bytes'] + (int)$a['db_bytes'], $p ? (int)$p['disk_mb'] * 1048576 : null),
                'inodes' => $r((int)$a['inodes'], $p ? (int)$p['inodes'] : null),
                'bandwidth' => $r((int)$a['bw_month'], $p ? (int)$p['bandwidth_mb'] * 1048576 : null)];
    }

    public static function evaluate(): void
    {
        $enforce = Settings::get('quota.enforce', '0') === '1';
        foreach (DB::all('SELECT a.*, u.username FROM usage_accounts a JOIN users u ON u.id = a.user_id') as $a) {
            $uid = (int)$a['user_id'];
            $bw = self::monthBytes($uid);
            $a['bw_month'] = $bw;
            $pkg = DB::one('SELECT p.* FROM packages p JOIN users u ON u.package_id = p.id WHERE u.id = ?', [$uid]);
            $ratios = self::ratios($a, $pkg);
            $max = max(array_map(fn($v) => $v ?? 0.0, $ratios));
            $state = $max >= 1.0 ? 'over' : ($max >= self::WARN ? 'warn' : 'ok');
            if ($state !== $a['state']) {
                Audit::log('quota.' . $state, $a['username'], array_map(fn($v) => $v === null ? null : round($v, 2), $ratios), ['id' => $uid, 'username' => 'system']);
            }
            DB::q('UPDATE usage_accounts SET bw_month = ?, state = ?, ratios = ? WHERE user_id = ?', [$bw, $state, json_encode($ratios), $uid]);
            self::enforce($uid, $enforce && $state === 'over');
        }
    }

    /** Suspend (503) or restore all sites of an account. Only touches domains whose state actually has to change. */
    private static function enforce(int $uid, bool $suspend): void
    {
        foreach (DB::all("SELECT * FROM domains WHERE user_id = ? AND type != 'alias' AND quota_suspended = ?", [$uid, $suspend ? 0 : 1]) as $d) {
            DB::update('domains', (int)$d['id'], ['quota_suspended' => $suspend ? 1 : 0]);
            try {
                DomainService::reapply(array_merge($d, ['quota_suspended' => $suspend ? 1 : 0]));
            } catch (\Throwable $e) {
                DB::update('domains', (int)$d['id'], ['quota_suspended' => (int)$d['quota_suspended']]);
                error_log('quota enforce ' . $d['name'] . ': ' . $e->getMessage());
            }
        }
    }
}
