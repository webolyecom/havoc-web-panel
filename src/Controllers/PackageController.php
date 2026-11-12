<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, DB, Response, Scope};

/** Hosting packages (plans). Admins and resellers own their own set; limits are applied via FPM cgroups + quota checks. */
final class PackageController extends Controller
{
    private const FIELDS = ['disk_mb' => [1, 10000000], 'bandwidth_mb' => [1, 100000000], 'inodes' => [1000, 100000000],
        'cpu_percent' => [10, 6400], 'ram_mb' => [128, 262144], 'max_domains' => [1, 10000],
        'max_databases' => [0, 10000], 'max_ftp' => [0, 10000], 'max_cron' => [0, 1000]];

    public function index(): Response
    {
        $u = $this->user();
        return view('packages/index', ['packages' => DB::all('SELECT p.*, (SELECT COUNT(*) FROM users x WHERE x.package_id = p.id) AS used FROM packages p WHERE owner_id = ? ORDER BY name', [$u['id']]),
            'fields' => array_keys(self::FIELDS)]);
    }

    public function store(): Response
    {
        $u = $this->user();
        $name = substr($this->req->str('name'), 0, 64);
        if ($name === '') {
            $this->flash('error', t('pkg.invalid'));
            return $this->to('/packages');
        }
        $data = ['owner_id' => $u['id'], 'name' => $name];
        foreach (self::FIELDS as $f => [$min, $max]) {
            $data[$f] = max($min, min($max, (int)$this->req->post($f, $min)));
        }
        DB::insert('packages', $data);
        Audit::log('package.create', $name);
        $this->flash('success', t('common.saved'));
        return $this->to('/packages');
    }

    public function delete(): Response
    {
        $p = Scope::row($this->user(), 'packages', (int)$this->param('id')) ?? $this->abort(404);
        if ((int)$p['owner_id'] !== (int)$this->user()['id']) {
            $this->abort(404);
        }
        DB::q('DELETE FROM packages WHERE id = ?', [$p['id']]);     // users.package_id -> NULL (ON DELETE SET NULL)
        Audit::log('package.delete', $p['name']);
        return $this->to('/packages');
    }
}
