<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response};

/** Server → Processes: what is running, who it belongs to, and ending runaway processes of hosting accounts. */
final class ProcessController extends Controller
{
    public function index(): Response
    {
        return view('server/processes');
    }

    /** JSON for the page (refreshed every few seconds). */
    public function list(): Response
    {
        try {
            $r = Ctl::call('proc.list', [], 30);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
        // hvN = hosting account of panel user N: show the panel user and a site of theirs
        $owners = [];
        foreach (DB::all("SELECT u.id, u.username, (SELECT name FROM domains d WHERE d.user_id = u.id AND d.type <> 'alias' ORDER BY d.id LIMIT 1) AS site FROM users u") as $u) {
            $owners['hv' . $u['id']] = ['panel_user' => $u['username'], 'site' => $u['site']];
        }
        $labels = ['root' => 'system', 'www-data' => 'web server', 'havoc' => 'panel', 'mysql' => 'MariaDB', 'redis' => 'Redis', 'memcache' => 'Memcached',
            'meilisearch' => 'Meilisearch', 'bind' => 'DNS', 'ftp' => 'FTP', 'nobody' => 'system'];
        foreach ($r['processes'] as &$p) {
            $o = $owners[$p['user']] ?? null;
            $p['label'] = $o ? $o['panel_user'] . ($o['site'] ? ' · ' . $o['site'] : '') : ($labels[$p['user']] ?? '');
            $p['account'] = (bool)preg_match('/^hv\d+$/D', $p['user']);    // hosting accounts only, as the helper enforces
        }
        unset($p);
        $mem = 0;
        if (preg_match('/^MemTotal:\s+(\d+)/m', (string)@file_get_contents('/proc/meminfo'), $m)) {
            $mem = (int)$m[1] * 1024;
        }
        return Response::json(['processes' => $r['processes'], 'at' => $r['at'], 'mem_total' => $mem,
            'cores' => max(1, preg_match_all('/^processor\s*:/m', (string)@file_get_contents('/proc/cpuinfo')))]);
    }

    public function signal(): Response
    {
        $pid = (int)$this->req->post('pid', 0);
        $sig = $this->req->post('signal', 'TERM') === 'KILL' ? 'KILL' : 'TERM';
        try {
            $r = Ctl::call('proc.signal', ['pid' => $pid, 'signal' => $sig]);
            Audit::log('process.' . strtolower($sig), (string)$pid, ['account' => $r['user']]);
            return Response::json(['ok' => true, 'message' => t('proc.signalled', ['pid' => $pid])]);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
