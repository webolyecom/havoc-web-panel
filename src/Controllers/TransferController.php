<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, DB, Response};
use Havoc\Services\{BackupService, OAuthService, TransferService};

/** Server → Site transfer (admin): keys that let another panel copy sites from here, and transfers into this server. */
final class TransferController extends Controller
{
    public function index(): Response
    {
        $newKey = $_SESSION['transfer_key'] ?? null;
        unset($_SESSION['transfer_key']);
        $transfers = DB::all('SELECT t.*, (SELECT COUNT(*) FROM transfer_items i WHERE i.transfer_id = t.id) AS n,
            (SELECT COUNT(*) FROM transfer_items i WHERE i.transfer_id = t.id AND i.status = \'done\') AS ok,
            (SELECT COUNT(*) FROM transfer_items i WHERE i.transfer_id = t.id AND i.status = \'failed\') AS bad,
            (SELECT COUNT(*) FROM transfer_items i WHERE i.transfer_id = t.id AND i.status IN (\'queued\', \'running\')) AS busy
            FROM transfers t ORDER BY t.id DESC LIMIT 30');
        return view('transfer/index', ['newKey' => $newKey, 'transfers' => $transfers,
            'keys' => DB::all('SELECT * FROM transfer_keys WHERE revoked = 0 AND expires_at > ? ORDER BY id DESC', [time()]),
            'domains' => DB::all("SELECT id, name, type FROM domains WHERE type != 'alias' AND status = 'active' ORDER BY name"),
            'base' => self::base(), 'myIp' => $this->req->ip()]);
    }

    /** The address other panels use to reach this one (config "transfer.base_url" when it differs from the browser's). */
    private static function base(): string
    {
        return rtrim((string)(\Havoc\Core\Config::get('transfer.base_url') ?: OAuthService::base()), '/');
    }

    public function keyStore(): Response
    {
        $all = $this->req->str('scope', 'all') === 'all';
        $ids = $all ? null : array_values(array_filter(array_map('intval', (array)$this->req->post('domains', []))));
        if (!$all && !$ids) {
            $this->flash('error', t('transfer.e_no_sites'));
            return $this->to('/transfers');
        }
        $ip = trim($this->req->str('allowed_ip'));
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->flash('error', t('transfer.e_ip'));
            return $this->to('/transfers');
        }
        $hours = (int)$this->req->post('hours', 24);
        [$key] = TransferService::createKey($this->user(), $this->req->str('label') ?: 'Transfer', $ids, $ip ?: null, in_array($hours, [1, 6, 24, 72, 168], true) ? $hours : 24, self::base());
        $_SESSION['transfer_key'] = $key;
        return $this->to('/transfers');
    }

    public function keyRevoke(): Response
    {
        DB::q('UPDATE transfer_keys SET revoked = 1 WHERE id = ?', [(int)$this->param('id')]);
        Audit::log('transfer.key_revoke', (string)$this->param('id'));
        $this->flash('success', t('transfer.revoked'));
        return $this->to('/transfers');
    }

    public function connect(): Response
    {
        try {
            $id = TransferService::connect($this->user(), (string)$this->req->post('key', ''));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/transfers');
        }
        return $this->to("/transfers/$id/select");
    }

    private function transfer(): array
    {
        return DB::one('SELECT * FROM transfers WHERE id = ?', [(int)$this->param('id')]) ?? $this->abort(404);
    }

    public function select(): Response
    {
        $t = $this->transfer();
        $info = json_decode((string)$t['source_info'], true) ?: [];
        $domains = $info['domains'] ?? [];
        return view('transfer/select', ['t' => $t, 'info' => $info, 'domains' => $domains, 'pre' => TransferService::preflight($domains),
            'users' => DB::all("SELECT id, username, role FROM users ORDER BY username"), 'free' => (int)@disk_free_space('/home')]);
    }

    public function start(): Response
    {
        $t = $this->transfer();
        $info = json_decode((string)$t['source_info'], true) ?: [];
        $byName = [];
        foreach ($info['domains'] ?? [] as $d) {
            $byName[$d['name']] = $d;
        }
        $pre = TransferService::preflight(array_values($byName));
        $items = [];
        foreach ((array)$this->req->post('sites', []) as $name => $opt) {
            $d = $byName[$name] ?? null;
            if (!$d || empty($opt['on']) || !empty($pre[$name]['block'])) {
                continue;
            }
            $dbs = array_values(array_intersect(array_column($d['dbs'], 'name'), array_keys(array_filter((array)($opt['dbs'] ?? [])))));
            $owner = (int)($opt['owner'] ?? 0);
            $items[] = ['domain' => $name, 'options' => ['source_id' => (int)$d['id'], 'type' => $d['type'], 'dbs' => $dbs, 'ssl' => !empty($opt['ssl']) && $d['ssl'] !== 'none', 'search' => !empty($opt['search']) && !empty($d['search']),
                'owner_id' => $owner > 0 && DB::one('SELECT id FROM users WHERE id = ?', [$owner]) ? $owner : null]];
        }
        if (!$items) {
            $this->flash('error', t('transfer.e_none_selected'));
            return $this->to("/transfers/{$t['id']}/select");
        }
        TransferService::start((int)$t['id'], $items);
        Audit::log('transfer.start', $t['source_url'], ['sites' => array_column($items, 'domain')]);
        return $this->to("/transfers/{$t['id']}");
    }

    public function show(): Response
    {
        $t = $this->transfer();
        return view('transfer/show', ['t' => $t, 'info' => json_decode((string)$t['source_info'], true) ?: [],
            'items' => DB::all('SELECT i.*, d.id AS did FROM transfer_items i LEFT JOIN domains d ON d.id = i.new_domain_id WHERE i.transfer_id = ? ORDER BY i.id', [$t['id']])]);
    }

    public function retry(): Response
    {
        $t = $this->transfer();
        $it = DB::one("SELECT * FROM transfer_items WHERE id = ? AND transfer_id = ? AND status = 'failed'", [(int)$this->param('item'), $t['id']]);
        if ($it && (!$it['new_domain_id'] || !DB::one('SELECT id FROM domains WHERE id = ?', [$it['new_domain_id']]))) {
            DB::q("UPDATE transfer_items SET status = 'queued', progress = 0, step = '', result = NULL WHERE id = ?", [$it['id']]);
            BackupService::spawn('transfer:run', (string)$t['id']);
        } elseif ($it) {
            $this->flash('error', t('transfer.e_retry_site'));
        }
        return $this->to("/transfers/{$t['id']}");
    }
}
