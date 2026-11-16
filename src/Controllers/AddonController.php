<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, Response};

/**
 * Admin-only: optional server add-ons (Redis, Meilisearch, Memcached, Fail2ban, Node.js): overview, one page per
 * add-on, install / uninstall in the background and their settings. Redis and Meilisearch settings stay in
 * StackController; everything is a shared, server-wide service.
 */
final class AddonController extends Controller
{
    public const ADDONS = ['redis', 'meilisearch', 'memcached', 'fail2ban', 'nodejs'];
    private const SERVICES = ['redis' => 'redis-server', 'meilisearch' => 'meilisearch', 'memcached' => 'memcached', 'fail2ban' => 'fail2ban'];
    public const POLICIES = ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'volatile-lru', 'volatile-lfu', 'allkeys-random', 'volatile-random', 'volatile-ttl'];

    private function call(string $action, array $args = [], int $timeout = 120): ?array
    {
        try {
            return Ctl::call($action, $args, $timeout);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return null;
        }
    }

    private function name(): string
    {
        $n = $this->param('name');
        return in_array($n, self::ADDONS, true) ? $n : $this->abort(404);
    }

    public function index(): Response
    {
        return view('addons/index', ['addons' => $this->call('addon.overview')['addons'] ?? []]);
    }

    public function show(): Response
    {
        $n = $this->name();
        $secret = $_SESSION['new_secret'] ?? null;
        unset($_SESSION['new_secret']);
        $data = match ($n) {
            'redis'       => $this->call('redis.status'),
            'meilisearch' => $this->call('meili.status'),
            default       => $this->call('addon.status', ['name' => $n]),
        } ?? [];
        return view('addons/show', ['name' => $n, 'st' => $data, 'secret' => $secret, 'policies' => self::POLICIES, 'myIp' => $this->req->ip()]);
    }

    public function install(): Response
    {
        $n = $this->name();
        $r = match ($n) {
            'redis'       => $this->call('redis.install'),
            'meilisearch' => $this->call('meili.install'),
            'nodejs'      => $this->call('addon.install', ['name' => $n, 'version' => $this->req->str('version', '24'), 'globals' => (array)$this->req->post('globals', [])]),
            default       => $this->call('addon.install', ['name' => $n]),
        };
        if ($r !== null) {
            Audit::log('addon.install', $n);
            $this->flash('success', t('stack.installing_started'));
        }
        return $this->to('/addons/' . $n);
    }

    public function uninstall(): Response
    {
        $n = $this->name();
        $purge = (bool)$this->req->post('purge');
        $r = match ($n) {
            'redis'       => $this->call('redis.uninstall', ['purge' => $purge]),
            'meilisearch' => $this->call('meili.uninstall', ['purge' => $purge]),
            default       => $this->call('addon.uninstall', ['name' => $n, 'purge' => $purge]),
        };
        if ($r !== null) {
            Audit::log('addon.uninstall', $n, ['purge' => $purge]);
            $this->flash('success', t($n === 'meilisearch' ? 'stack.meili_removed' : 'stack.removing_started'));
        }
        return $this->to('/addons/' . $n);
    }

    /** "Activate" = enable at boot + start, "Deactivate" = stop + disable. */
    public function service(): Response
    {
        $n = $this->name();
        $unit = self::SERVICES[$n] ?? $this->abort(404);
        $op = $this->param('op');
        $steps = ['activate' => ['enable', 'start'], 'deactivate' => ['stop', 'disable'], 'restart' => ['restart']][$op] ?? $this->abort(404);
        foreach ($steps as $s) {
            if ($this->call('service.control', ['name' => $unit, 'op' => $s], 180) === null) {
                return $this->to('/addons/' . $n);
            }
        }
        Audit::log('addon.' . $op, $n);
        $this->flash('success', t('common.done'));
        return $this->to('/addons/' . $n);
    }

    public function memcachedSave(): Response
    {
        if ($this->call('memcached.set', ['memory' => (int)$this->req->post('memory', 0), 'conns' => (int)$this->req->post('conns', 0)], 90) !== null) {
            Audit::log('memcached.set');
            $this->flash('success', t('common.saved'));
        }
        return $this->to('/addons/memcached');
    }

    public function memcachedFlush(): Response
    {
        if ($this->call('memcached.flush') !== null) {
            Audit::log('memcached.flush');
            $this->flash('success', t('addon.mc_flushed'));
        }
        return $this->to('/addons/memcached');
    }

    public function f2bSave(): Response
    {
        $ips = preg_split('/[\s,]+/', $this->req->str('ignoreip')) ?: [];
        $jails = [];
        foreach ((array)$this->req->post('jails', []) as $j => $v) {
            $jails[(string)$j] = (bool)$v;
        }
        $args = ['bantime' => (int)$this->req->post('bantime', 0), 'findtime' => (int)$this->req->post('findtime', 0),
            'maxretry' => (int)$this->req->post('maxretry', 0), 'ignoreip' => array_values(array_filter($ips, 'strlen')), 'jails' => $jails];
        if ($this->call('fail2ban.apply', $args, 90) !== null) {
            Audit::log('fail2ban.settings');
            $this->flash('success', t('common.saved'));
        }
        return $this->to('/addons/fail2ban');
    }

    public function f2bBan(): Response
    {
        $op = $this->param('op');
        in_array($op, ['ban', 'unban'], true) || $this->abort(404);
        $unban = $op === 'unban';
        $ip = $this->req->str('ip');
        if ($this->call('fail2ban.ban', ['ip' => $ip, 'jail' => $this->req->str('jail', 'sshd'), 'unban' => $unban]) !== null) {
            Audit::log($unban ? 'fail2ban.unban' : 'fail2ban.ban', $ip);
            $this->flash('success', t($unban ? 'addon.f2b_unbanned' : 'addon.f2b_banned', ['ip' => $ip]));
        }
        return $this->to('/addons/fail2ban');
    }

    public function nodeGlobals(): Response
    {
        if ($this->call('node.globals', ['globals' => (array)$this->req->post('globals', [])]) !== null) {
            Audit::log('nodejs.globals');
            $this->flash('success', t('stack.installing_started'));
        }
        return $this->to('/addons/nodejs');
    }
}
