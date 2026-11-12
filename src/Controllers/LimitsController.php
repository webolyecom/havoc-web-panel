<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response, Scope, Settings};
use Havoc\Services\{BackupService, UsageService};

final class LimitsController extends Controller
{
    public function index(): Response
    {
        $u = $this->user();
        $ids = Scope::userIds($u);
        $sql = 'SELECT a.*, u.username, p.name AS pkg, p.disk_mb, p.inodes AS max_inodes, p.bandwidth_mb, p.ram_mb, p.cpu_percent
                FROM usage_accounts a JOIN users u ON u.id = a.user_id LEFT JOIN packages p ON p.id = u.package_id';
        $rows = $ids === null ? DB::all("$sql ORDER BY u.username")
            : DB::all("$sql WHERE a.user_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY u.username', $ids);
        foreach ($rows as &$r) {
            $r['ratios'] = UsageService::ratios($r, $r['pkg'] === null ? null : ['disk_mb' => $r['disk_mb'], 'inodes' => $r['max_inodes'], 'bandwidth_mb' => $r['bandwidth_mb']]);
        }
        unset($r);
        $doms = array_slice(array_values(array_filter(Scope::domains($u), fn($d) => $d['type'] !== 'alias' && $d['status'] === 'active')), 0, 100);
        $fpm = [];
        if ($doms) {
            try {
                $fpm = Ctl::call('usage.fpm', ['ids' => array_map('intval', array_column($doms, 'id'))], 30)['fpm'] ?? [];
            } catch (\Throwable) {
            }
        }
        return view('limits/index', ['rows' => $rows, 'domains' => $doms, 'fpm' => $fpm, 'enforce' => Settings::get('quota.enforce', '0') === '1',
            'scanned' => (string)DB::q('SELECT MAX(updated_at) FROM usage_accounts')->fetchColumn()]);
    }

    public function scan(): Response
    {
        BackupService::spawn('usage:scan');
        Audit::log('usage.scan');
        $this->flash('success', t('limits.scan_started'));
        return $this->to('/limits');
    }

    public function settings(): Response
    {
        Settings::set('quota.enforce', $this->req->post('enforce') ? '1' : '0');
        Audit::log('quota.enforce', (string)Settings::get('quota.enforce'));
        try {
            UsageService::evaluate();
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/limits');
    }
}
