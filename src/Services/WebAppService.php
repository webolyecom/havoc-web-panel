<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB, Scope};
use Havoc\Ctl\Validator;

/**
 * Applications: a long-running process (e.g. a Go game server using WebSocket) bound to a site's domain and reverse-proxied
 * by nginx. The panel keeps the record and the vhost; the root helper (ctl/lib/Apps.php, actions app.*) owns the system
 * user, files, systemd unit, deploys and runtime. Secrets live only in /etc/havoc-apps/<name>.env (root-owned) and are
 * never returned — tools and the UI see key names only. Deletion is panel-only (destroy()), to match the connector policy.
 */
final class WebAppService
{
    public const PORT_MIN = 20000;
    public const PORT_MAX = 29999;
    public const KEEP_RELEASES = 5;
    private const SYSTEM = ['id' => null, 'username' => 'system'];

    public static function validName(string $n): string
    {
        $n = strtolower(trim($n));
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $n)) {
            throw new \InvalidArgumentException(t('app.e_name'));
        }
        return $n;
    }

    /** A relative, safe path segment list from user input (health/ws/shared/start). */
    private static function cleanPathList(mixed $in, int $max): array
    {
        $out = [];
        foreach ((array)$in as $p) {
            $p = trim((string)$p, " /");
            if ($p !== '' && !preg_match('#(^|/)\.\.(/|$)#', $p) && preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $p)) {
                $out[] = $p;
            }
        }
        return array_values(array_slice(array_unique($out), 0, $max));
    }

    private static function urlPath(string $p, string $default): string
    {
        $p = '/' . ltrim(trim($p), '/');
        return preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]{0,200}$#', $p) ? rtrim($p, '/') ?: $p : $default;
    }

    public static function allocatePort(): int
    {
        $used = array_column(DB::all('SELECT port FROM web_apps'), 'port');
        $used = array_flip(array_map('intval', $used));
        for ($i = 0; $i < 500; $i++) {
            $p = random_int(self::PORT_MIN, self::PORT_MAX);
            if (!isset($used[$p])) {
                return $p;
            }
        }
        throw new \RuntimeException(t('app.e_noport'));
    }

    /** Applications create system users and systemd services, so every operation is administrator-only (as in the panel UI). */
    private static function admin(array $actor): void
    {
        if (($actor['role'] ?? '') !== 'admin') {
            throw new \DomainException(t('app.e_admin'));
        }
    }

    /** The app row the actor may manage (via its domain), or null. */
    public static function forActor(array $actor, string $name): ?array
    {
        self::admin($actor);
        $a = DB::one('SELECT * FROM web_apps WHERE name = ?', [strtolower(trim($name))]);
        if (!$a || !Scope::domain($actor, (int)$a['domain_id'])) {
            return null;
        }
        return $a;
    }

    public static function require(array $actor, string $name): array
    {
        return self::forActor($actor, $name) ?? throw new \DomainException(t('app.e_notfound', ['name' => $name]));
    }

    public static function list(array $actor): array
    {
        self::admin($actor);
        $out = [];
        foreach (DB::all('SELECT w.*, d.name AS domain FROM web_apps w JOIN domains d ON d.id = w.domain_id ORDER BY w.name') as $a) {
            if (Scope::domain($actor, (int)$a['domain_id'])) {
                $out[] = $a;
            }
        }
        return $out;
    }

    public static function domainRow(array $actor, string $domain): array
    {
        self::admin($actor);
        $d = DB::one('SELECT * FROM domains WHERE name = ?', [strtolower(trim($domain))]);
        $d = $d ? Scope::domain($actor, (int)$d['id']) : null;
        if (!$d) {
            throw new \DomainException(t('app.e_domain'));
        }
        if ($d['type'] === 'alias') {
            throw new \DomainException(t('app.e_alias'));
        }
        if ($d['status'] !== 'active') {
            throw new \DomainException(t('app.e_domain_inactive'));
        }
        if (DB::one('SELECT id FROM web_apps WHERE domain_id = ?', [$d['id']])) {
            throw new \DomainException(t('app.e_domain_taken'));
        }
        return $d;
    }

    /**
     * Create an application: record, system user, directories, env file (PORT/ADDR auto), systemd unit, and the domain's
     * vhost switched to reverse proxy. No process starts yet (the first deploy provides current/<start_command>).
     */
    public static function create(array $actor, array $in): array
    {
        $name = self::validName((string)($in['name'] ?? ''));
        if (DB::one('SELECT id FROM web_apps WHERE name = ?', [$name])) {
            throw new \DomainException(t('app.e_name_taken', ['name' => $name]));
        }
        $d = self::domainRow($actor, (string)($in['domain'] ?? ''));
        $start = trim((string)($in['start_command'] ?? ''));
        if (!preg_match('#^\./[A-Za-z0-9._/-]{1,100}$#', $start) || str_contains($start, '..')) {
            throw new \InvalidArgumentException(t('app.e_start'));
        }
        $health = self::urlPath((string)($in['health_path'] ?? '/healthz'), '/healthz');
        $ws = array_map(static fn($p) => self::urlPath($p, ''), self::cleanPathList($in['ws_paths'] ?? [], 10));
        $ws = array_values(array_filter($ws));
        $shared = self::cleanPathList($in['shared_paths'] ?? [], 20);
        $port = self::allocatePort();

        $id = DB::insert('web_apps', ['name' => $name, 'domain_id' => (int)$d['id'], 'port' => $port, 'start_command' => $start,
            'health_path' => $health, 'ws_paths' => json_encode($ws), 'shared_paths' => json_encode($shared), 'status' => 'new']);
        try {
            $env = array_filter([
                'PORT' => (string)$port,
                'ADDR' => '127.0.0.1:' . $port,
            ] + self::envInput($in['env'] ?? []));
            Ctl::call('app.provision', ['name' => $name, 'port' => $port, 'start_command' => $start,
                'health_path' => $health, 'ws_paths' => $ws, 'shared_paths' => $shared, 'env' => $env], 120);
            DomainService::reapply(DB::one('SELECT * FROM domains WHERE id = ?', [$d['id']]));
        } catch (\Throwable $e) {
            DB::q('DELETE FROM web_apps WHERE id = ?', [$id]);
            try {
                Ctl::call('app.destroy', ['name' => $name, 'purge' => true], 120);
                DomainService::reapply(DB::one('SELECT * FROM domains WHERE id = ?', [$d['id']]));
            } catch (\Throwable) {
            }
            throw $e;
        }
        Audit::log('app.create', $name, ['domain' => $d['name'], 'port' => $port], $actor);
        return self::require($actor, $name);
    }

    /** Accepts {KEY: value} and keeps only valid env keys and single-line values. */
    private static function envInput(mixed $in): array
    {
        $out = [];
        foreach ((array)$in as $k => $v) {
            if (preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/', (string)$k) && !preg_match('/[\r\n\0]/', (string)$v)) {
                $out[(string)$k] = (string)$v;
            }
        }
        return $out;
    }

    /** Env keys only (values stay in the root-owned file). */
    public static function envKeys(array $actor, string $name): array
    {
        $a = self::require($actor, $name);
        return Ctl::call('app.env', ['name' => $a['name']], 30)['keys'] ?? [];
    }

    public static function setEnv(array $actor, string $name, array $set, array $unset, bool $restart): array
    {
        $a = self::require($actor, $name);
        $res = Ctl::call('app.env', ['name' => $a['name'], 'set' => self::envInput($set),
            'unset' => array_values(array_filter(array_map('strval', $unset), static fn($k) => preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/', $k))),
            'restart' => $restart], 60);
        Audit::log('app.set_env', $a['name'], ['set' => array_keys(self::envInput($set)), 'unset' => $unset, 'restart' => $restart], $actor);
        return $res;
    }

    /**
     * Deploy an uploaded archive (from the app's domain, via create_upload_link / the file manager): new release, health
     * check, automatic rollback on failure. $upload is a path relative to the domain's public_html.
     */
    public static function deploy(array $actor, string $name, string $upload): array
    {
        $a = self::require($actor, $name);
        $d = DB::one('SELECT * FROM domains WHERE id = ?', [$a['domain_id']]);
        DB::update('web_apps', (int)$a['id'], ['status' => 'deploying', 'note' => null]);
        $ref = gmdate('Ymd-His');
        try {
            $r = Ctl::call('app.deploy', ['name' => $a['name'], 'account' => 'hv' . $d['user_id'], 'domain' => $d['name'], 'src' => $upload, 'ref' => $ref,
                'start_command' => $a['start_command'], 'health_path' => $a['health_path'], 'shared_paths' => json_decode($a['shared_paths'], true) ?: [],
                'port' => (int)$a['port'], 'keep' => self::KEEP_RELEASES], 600);
        } catch (\Throwable $e) {
            DB::update('web_apps', (int)$a['id'], ['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 400)]);
            throw $e;
        }
        // make sure the domain's vhost is the reverse-proxy variant (and nginx reloaded) after every deploy
        try {
            DomainService::reapply($d);
        } catch (\Throwable $e) {
            $r['proxy_error'] = mb_substr($e->getMessage(), 0, 300);
        }
        $ok = !empty($r['health']);
        DB::insert('web_app_releases', ['app_id' => (int)$a['id'], 'ref' => $r['ref'] ?? $ref, 'active' => $ok ? 1 : 0,
            'health' => $ok ? 'ok' : 'failed', 'note' => mb_substr((string)($r['note'] ?? ''), 0, 400)]);
        if ($ok) {
            DB::q('UPDATE web_app_releases SET active = 0 WHERE app_id = ? AND ref <> ?', [(int)$a['id'], $r['ref'] ?? $ref]);
        }
        DB::update('web_apps', (int)$a['id'], ['status' => $ok ? 'running' : 'failed', 'note' => $ok ? null : mb_substr((string)($r['note'] ?? ''), 0, 400)]);
        Audit::log('app.deploy', $a['name'], ['ref' => $r['ref'] ?? $ref, 'health' => $ok ? 'ok' : 'failed', 'rolled_back' => $r['rolled_back'] ?? false], $actor);
        return $r + ['app' => $a['name']];
    }

    public static function rollback(array $actor, string $name, ?string $ref): array
    {
        $a = self::require($actor, $name);
        $r = Ctl::call('app.rollback', ['name' => $a['name'], 'ref' => $ref, 'health_path' => $a['health_path'], 'port' => (int)$a['port']], 300);
        $to = (string)($r['ref'] ?? '');
        if ($to !== '') {
            DB::q('UPDATE web_app_releases SET active = (ref = ?) WHERE app_id = ?', [$to, (int)$a['id']]);
            DB::update('web_apps', (int)$a['id'], ['status' => !empty($r['health']) ? 'running' : 'failed']);
        }
        Audit::log('app.rollback', $a['name'], ['ref' => $to], $actor);
        return $r;
    }

    public static function control(array $actor, string $name, string $action): array
    {
        $a = self::require($actor, $name);
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new \InvalidArgumentException('action');
        }
        $r = Ctl::call('app.control', ['name' => $a['name'], 'action' => $action], 60);
        DB::update('web_apps', (int)$a['id'], ['status' => $action === 'stop' ? 'stopped' : ($r['active'] ?? false ? 'running' : 'failed')]);
        Audit::log('app.' . $action, $a['name'], [], $actor);
        return $r;
    }

    public static function status(array $actor, string $name): array
    {
        $a = self::require($actor, $name);
        $d = DB::one('SELECT name FROM domains WHERE id = ?', [$a['domain_id']]);
        $rt = Ctl::call('app.status', ['name' => $a['name'], 'port' => (int)$a['port'], 'health_path' => $a['health_path'], 'domain' => $d['name']], 40);
        $proxy = $rt['proxy'] ?? null;
        unset($rt['proxy']);
        return ['name' => $a['name'], 'domain' => $d['name'], 'port' => (int)$a['port'], 'status' => $a['status'],
            'start_command' => $a['start_command'], 'health_path' => $a['health_path'],
            'ws_paths' => json_decode($a['ws_paths'], true) ?: [], 'shared_paths' => json_decode($a['shared_paths'], true) ?: [],
            'proxy' => $proxy, 'runtime' => $rt, 'releases' => self::releases((int)$a['id'])];
    }

    public static function releases(int $appId): array
    {
        return array_map(static fn($r) => ['ref' => $r['ref'], 'active' => (bool)$r['active'], 'health' => $r['health'], 'at' => $r['created_at'] . ' UTC', 'note' => $r['note']],
            DB::all('SELECT * FROM web_app_releases WHERE app_id = ? ORDER BY id DESC LIMIT 30', [$appId]));
    }

    public static function logs(array $actor, string $name, int $lines, ?string $since, ?string $grep): array
    {
        $a = self::require($actor, $name);
        return Ctl::call('app.logs', ['name' => $a['name'], 'lines' => max(1, min(2000, $lines)), 'since' => $since, 'grep' => $grep], 40);
    }

    public static function http(array $actor, string $name, string $method, string $path, array $headers, string $body): array
    {
        $a = self::require($actor, $name);
        $r = Ctl::call('app.http', ['name' => $a['name'], 'port' => (int)$a['port'], 'method' => strtoupper($method),
            'path' => $path, 'headers' => $headers, 'body' => $body], 70);
        Audit::log('app.http', $a['name'], ['method' => strtoupper($method), 'path' => $path, 'status' => $r['status'] ?? 0], $actor);
        return $r;
    }

    public static function exec(array $actor, string $name, array $args, int $timeout): array
    {
        $a = self::require($actor, $name);
        $r = Ctl::call('app.exec', ['name' => $a['name'], 'args' => array_values(array_map('strval', $args)), 'timeout' => max(1, min(60, $timeout))], max(5, min(70, $timeout + 10)));
        Audit::log('app.exec', $a['name'], ['args' => array_slice($args, 0, 10)], $actor);
        return $r;
    }

    public static function readFile(array $actor, string $name, string $path): array
    {
        $a = self::require($actor, $name);
        return Ctl::call('app.read_file', ['name' => $a['name'], 'path' => $path], 40);
    }

    public static function writeFile(array $actor, string $name, string $path, string $content): array
    {
        $a = self::require($actor, $name);
        $r = Ctl::call('app.write_file', ['name' => $a['name'], 'path' => $path, 'b64' => base64_encode($content)], 60);
        Audit::log('app.write_file', $a['name'], ['path' => $path, 'bytes' => strlen($content)], $actor);
        return $r;
    }

    /** Panel-only (not exposed to the connector): remove the app, its user, files, unit, and restore the plain vhost. */
    public static function destroy(array $actor, string $name): void
    {
        $a = self::require($actor, $name);
        $d = DB::one('SELECT * FROM domains WHERE id = ?', [$a['domain_id']]);
        Ctl::call('app.destroy', ['name' => $a['name'], 'purge' => true], 120);
        DB::q('DELETE FROM web_apps WHERE id = ?', [(int)$a['id']]);
        if ($d) {
            DomainService::reapply($d);
        }
        Audit::log('app.destroy', $a['name'], ['domain' => $d['name'] ?? ''], $actor);
    }

    /** Reverse-proxy spec for a domain's vhost, or null when the domain has no app. Used by DomainService::ctlArgs. */
    public static function proxyFor(int $domainId): ?array
    {
        $a = DB::one('SELECT name, port, ws_paths FROM web_apps WHERE domain_id = ?', [$domainId]);
        return $a ? ['name' => $a['name'], 'port' => (int)$a['port'], 'ws_paths' => json_decode($a['ws_paths'], true) ?: []] : null;
    }
}
