<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response};

/** Server → MySQL queries: open connections and running statements of MariaDB, stopping them one by one or by rule. */
final class DbProcessController extends Controller
{
    public function index(): Response
    {
        return view('server/mysql');
    }

    /** JSON for the page (refreshed every few seconds). */
    public function list(): Response
    {
        try {
            $r = Ctl::call('db.processlist', [], 30);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
        // who is behind a connection: databases and their users are named hvN_xxx (N = panel user), hvpma_ = phpMyAdmin
        $dbs = [];
        foreach (DB::all('SELECT i.name, d.name AS site, u.username FROM db_instances i JOIN users u ON u.id = i.user_id LEFT JOIN domains d ON d.id = i.domain_id') as $x) {
            $dbs[$x['name']] = $x;
        }
        $users = array_column(DB::all('SELECT id, username FROM users'), 'username', 'id');
        foreach ($r['threads'] as &$t) {
            $who = $dbs[$t['user']] ?? $dbs[(string)$t['db']] ?? null;
            if ($who) {
                $t['label'] = $who['username'] . ($who['site'] ? ' · ' . $who['site'] : '');
            } elseif (str_starts_with((string)$t['user'], 'hvpma_')) {
                $t['label'] = 'phpMyAdmin';
            } elseif (preg_match('/^hv(\d+)_/', (string)$t['user'], $m) && isset($users[(int)$m[1]])) {
                $t['label'] = $users[(int)$m[1]];
            } else {
                $t['label'] = $t['system'] ? 'MariaDB' : ($t['user'] === 'root' ? t('dbproc.root') : '');
            }
        }
        unset($t);
        return Response::json($r);
    }

    /** One or more threads by id (mode query|connection), or by rule (long|sleep + seconds [+ db]). */
    public function kill(): Response
    {
        $b = $this->req->json();
        $args = ['mode' => ($b['mode'] ?? 'query') === 'connection' ? 'connection' : 'query'];
        if (in_array($b['rule'] ?? '', ['long', 'sleep'], true)) {
            $args += ['rule' => $b['rule'], 'seconds' => max(1, min(86400, (int)($b['seconds'] ?? 60)))];
            if (!empty($b['db']) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string)$b['db'])) {
                $args['db'] = (string)$b['db'];
            }
        } else {
            $args['ids'] = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? [])), static fn($i) => $i > 0));
            if (!$args['ids']) {
                return Response::json(['ok' => false, 'error' => t('dbproc.none_selected')], 422);
            }
        }
        try {
            $r = Ctl::call('db.kill', $args, 60);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        $n = count($r['killed']);
        if ($n) {
            Audit::log('mysql.kill_' . $r['mode'], implode(',', array_column($r['killed'], 'id')),
                ['rule' => $args['rule'] ?? null, 'seconds' => $args['seconds'] ?? null, 'db' => $args['db'] ?? null,
                 'queries' => array_slice(array_map(static fn($k) => $k['user'] . ': ' . $k['info'], $r['killed']), 0, 20)]);
        }
        return Response::json(['ok' => true, 'count' => $n,
            'message' => $n ? t($r['mode'] === 'connection' ? 'dbproc.closed' : 'dbproc.stopped', ['n' => $n]) : t('dbproc.nothing')]);
    }
}
