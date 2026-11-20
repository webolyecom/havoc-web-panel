<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Ctl, DB, Response};
use Havoc\Services\{DomainService, PoolService};

/** Server → Resources: what uses the memory and CPU, and quick RAM/CPU/worker limits for every site's PHP. */
final class ResourceController extends Controller
{
    /** systemd service => [label, settings link] */
    private const KNOWN = [
        'mariadb' => ['MariaDB', '/databases/tuning'], 'mysql' => ['MySQL', '/databases/tuning'],
        'meilisearch' => ['Meilisearch', '/addons/meilisearch'], 'redis-server' => ['Redis', '/addons/redis'], 'memcached' => ['Memcached', '/addons/memcached'],
        'nginx' => ['Nginx', '/server/webserver/settings'], 'lshttpd' => ['LiteSpeed', '/server/webserver/settings'], 'lsws' => ['LiteSpeed', '/server/webserver/settings'],
        'openlitespeed' => ['OpenLiteSpeed', '/server/webserver/settings'], 'havoc-panel-nginx' => ['Havoc panel (Nginx)', null], 'havoc-panel-fpm' => ['Havoc panel (PHP)', null],
        'havoc-pma-fpm' => ['phpMyAdmin', null], 'fail2ban' => ['Fail2ban', '/addons/fail2ban'], 'pure-ftpd' => ['FTP', '/ftp'], 'proftpd' => ['FTP', '/ftp'],
        'pdns' => ['PowerDNS', '/dns'], 'postfix' => ['Postfix', null], 'clamav-daemon' => ['ClamAV', null],
    ];

    public function index(): Response
    {
        try {
            $r = Ctl::call('server.resources', [], 60);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/server');
        }
        $sites = [];
        foreach (DB::all("SELECT d.*, u.username, u.package_id, u.role AS owner_role FROM domains d JOIN users u ON u.id = d.user_id WHERE d.type != 'alias' ORDER BY d.name") as $d) {
            $owner = ['id' => $d['user_id'], 'package_id' => $d['package_id']];
            $eff = PoolService::effective($d, $owner);
            $unit = $r['units']['havoc-fpm-' . $d['id']] ?? null;
            $sites[] = ['d' => $d, 'pool' => PoolService::stored($d), 'eff' => $eff, 'pkg' => PoolService::packageLimits($owner),
                'children' => $eff['pool']['max_children'] ?? PoolService::autoChildren((int)$eff['limits']['ram_mb']), 'unit' => $unit];
        }
        $services = [];
        foreach ($r['units'] as $name => $u) {
            if (str_starts_with($name, 'havoc-fpm-')) {
                continue;                                             // shown per site
            }
            [$label, $link] = self::KNOWN[$name] ?? (preg_match('/^php([\d.]+)-fpm$/', $name, $m) ? ['PHP ' . $m[1] . ' FPM (system)', null] : [$name, null]);
            $services[] = ['name' => $name, 'label' => $label, 'link' => $link] + $u;
        }
        $phpTotal = array_sum(array_map(static fn($s) => (int)($s['unit']['memory'] ?? 0), $sites));
        return view('server/resources', ['r' => $r, 'sites' => $sites, 'services' => $services, 'phpTotal' => $phpTotal]);
    }

    /** Quick limits of one site (RAM, CPU, workers, mode); its other pool values stay. */
    public function save(): Response
    {
        $d = DB::one('SELECT * FROM domains WHERE id = ?', [(int)$this->param('id')]) ?? $this->abort(404);
        $cur = PoolService::stored($d);
        $in = [];
        foreach (PoolService::KEYS as $k) {
            $in[$k] = $cur[$k] === null ? '' : (string)$cur[$k];
        }
        foreach (['ram_mb', 'cpu_percent', 'max_children', 'pm'] as $k) {
            $in[$k] = (string)$this->req->post($k, '');
        }
        $in['ram_unlimited'] = $this->req->post('ram_unlimited');
        $in['cpu_unlimited'] = $this->req->post('cpu_unlimited');
        if ($in['ram_unlimited']) {
            $in['ram_mb'] = '';
        }
        if ($in['cpu_unlimited']) {
            $in['cpu_percent'] = '';
        }
        try {
            $stored = PoolService::validate($in);
            DomainService::update($this->user(), $d, ['php_pool' => PoolService::encode($stored)]);
            $this->flash('success', t('res.saved', ['site' => $d['name']]));
        } catch (\Throwable $e) {
            $this->flash('error', $d['name'] . ': ' . $e->getMessage());
        }
        return $this->to('/server/resources#site-' . $d['id']);
    }
}
