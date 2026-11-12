<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Config, Ctl, DB, Scope};
use Havoc\Ctl\Validator;

final class DomainService
{
    public static function phpVersions(): array
    {
        try {
            $v = Ctl::call('php.list')['versions'] ?? [];
        } catch (\Throwable) {
            $v = [];
        }
        return $v ?: Config::get('php_versions', ['8.2']);
    }

    public static function ctlArgs(array $d, array $owner, array $extra = []): array
    {
        $pkg = $owner['package_id'] ? DB::one('SELECT * FROM packages WHERE id = ?', [$owner['package_id']]) : null;
        return $extra + [
            'id' => (int)$d['id'], 'domain' => $d['name'], 'account' => 'hv' . $owner['id'],
            'php' => $d['php_version'], 'extensions' => $d['php_extensions'] ? json_decode($d['php_extensions'], true) : null,
            'opcache' => ['enable' => $d['opcache_enabled'], 'memory' => $d['opcache_memory']],
            'ssl_mode' => $d['ssl_mode'], 'force_https' => (bool)$d['force_https'], 'hsts' => (bool)$d['hsts'],
            'waf' => (bool)$d['waf'], 'logs' => (bool)$d['logs_enabled'], 'suspended' => !empty($d['quota_suspended']),
            'aliases' => array_values(array_diff(self::serverNames($d), [$d['name']])),
            'redirects' => DB::all('SELECT source, target, code FROM redirects WHERE domain_id = ? ORDER BY id', [$d['id']]),
            'limits' => ['ram_mb' => $pkg['ram_mb'] ?? 1024, 'cpu_percent' => $pkg['cpu_percent'] ?? 100],
            'php_ini' => json_decode((string)($d['php_ini'] ?? ''), true) ?: [],
            'php_ini_admin' => (string)($d['php_ini_admin'] ?? ''),
            'nginx_custom' => (string)($d['nginx_custom'] ?? ''),
            'nginx_custom_trusted' => !empty($d['nginx_custom_trusted']),
        ];
    }

    public static function create(array $actor, int $ownerId, string $name, string $php, string $type = 'main', ?int $parentId = null): array
    {
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$ownerId]);
        if (!$owner || !Scope::canManageUser($actor, $owner)) {
            throw new \DomainException('Forbidden');
        }
        $name = Validator::domain($name);
        Validator::php($php);
        if (!in_array($type, ['main', 'addon', 'sub', 'alias'], true)) {
            throw new \InvalidArgumentException('Bad type');
        }
        $parent = null;
        if (in_array($type, ['sub', 'alias'], true)) {
            $parent = $parentId ? Scope::domain($actor, $parentId) : null;
            if (!$parent || (int)$parent['user_id'] !== $ownerId || !in_array($parent['type'], ['main', 'addon'], true)) {
                throw new \DomainException('parent_invalid');
            }
            if ($type === 'sub' && !str_ends_with($name, '.' . $parent['name'])) {
                throw new \DomainException('sub_mismatch');
            }
        }
        if (DB::one('SELECT id FROM domains WHERE name = ?', [$name])) {
            throw new \DomainException('domain_exists');
        }
        if ($owner['package_id'] && $type !== 'alias') {
            $max = (int)DB::q('SELECT max_domains FROM packages WHERE id = ?', [$owner['package_id']])->fetchColumn();
            $cnt = (int)DB::q("SELECT COUNT(*) FROM domains WHERE user_id = ? AND type != 'alias'", [$ownerId])->fetchColumn();
            if ($cnt >= $max) {
                throw new \DomainException('quota_domains');
            }
        }
        $id = DB::insert('domains', ['user_id' => $ownerId, 'name' => $name, 'type' => $type, 'parent_id' => $parent['id'] ?? null,
            'php_version' => $parent['php_version'] ?? $php, 'status' => 'pending']);
        try {
            if ($type === 'alias') {
                self::reapply($parent);                         // alias = extra server_name on the parent's vhost
                DB::update('domains', $id, ['status' => 'active']);
            } else {
                $d   = DB::one('SELECT * FROM domains WHERE id = ?', [$id]);
                $res = Ctl::call('domain.apply', self::ctlArgs($d, $owner));
                DB::update('domains', $id, ['status' => 'active', 'docroot' => $res['docroot']]);
            }
        } catch (\Throwable $e) {
            DB::q('DELETE FROM domains WHERE id = ?', [$id]);   // roll back panel state
            if ($type === 'alias') {
                try { self::reapply($parent); } catch (\Throwable) {}
            }
            throw $e;
        }
        Audit::log('domain.create', $name, ['type' => $type, 'php' => $php], $actor);
        $new = DB::one('SELECT * FROM domains WHERE id = ?', [$id]);
        if (SslService::enabled()) {
            if ($type === 'alias') {
                // the alias needs to be added to the parent's certificate
                if ($parent['ssl_mode'] === 'letsencrypt' || in_array($parent['ssl_auto'], ['issued', 'pending', 'running'], true)) {
                    SslService::queue(DB::one('SELECT * FROM domains WHERE id = ?', [$parent['id']]));
                }
            } else {
                SslService::queue($new);
            }
        }
        return $new;
    }

    /** Persist changed fields, then re-apply the whole vhost + FPM config. */
    public static function update(array $actor, array $d, array $fields): void
    {
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$d['user_id']]);
        $new = array_merge($d, $fields);
        Ctl::call('domain.apply', self::ctlArgs($new, $owner));   // validated + tested by the helper first,
        DB::update('domains', (int)$d['id'], $fields);           // stored only when it was applied
        $log = $fields;
        foreach (['nginx_custom', 'php_ini_admin'] as $big) {
            if (isset($log[$big])) {
                $log[$big] = mb_strlen((string)$log[$big]) . ' chars';
            }
        }
        Audit::log('domain.update', $d['name'], $log, $actor);
    }

    public static function delete(array $actor, array $d, bool $purge): void
    {
        if ($d['type'] === 'alias') {
            $parent = DB::one('SELECT * FROM domains WHERE id = ?', [$d['parent_id']]);
            DB::q('DELETE FROM domains WHERE id = ?', [$d['id']]);
            if ($parent) {
                self::reapply($parent);
            }
            Audit::log('domain.delete', $d['name'], ['type' => 'alias'], $actor);
            return;
        }
        foreach (DB::all("SELECT * FROM domains WHERE parent_id = ? AND type = 'sub'", [$d['id']]) as $child) {
            self::delete($actor, $child, $purge);              // subdomains own vhosts/FPM units: remove them properly
        }
        foreach (DB::all('SELECT * FROM ftp_accounts WHERE domain_id = ?', [$d['id']]) as $f) {
            try {
                Ctl::call('ftp.delete', ['protocol' => $f['protocol'], 'login' => $f['login']]);
            } catch (\Throwable $e) {
                error_log('ftp cleanup: ' . $e->getMessage());
            }
        }
        if (!empty($d['dns_enabled'])) {
            try {
                Ctl::call('dns.delete', ['domain' => $d['name']]);
            } catch (\Throwable $e) {
                error_log('dns cleanup: ' . $e->getMessage());
            }
        }
        Ctl::call('domain.delete', ['id' => (int)$d['id'], 'domain' => $d['name'], 'account' => 'hv' . $d['user_id'], 'purge' => $purge]);
        DB::q('DELETE FROM domains WHERE id = ?', [$d['id']]);   // alias rows cascade
        Audit::log('domain.delete', $d['name'], ['purge' => $purge], $actor);
    }

    /** Re-render vhost + FPM unit without changing fields (e.g. after a package/limit change). */
    public static function reapply(array $d): void
    {
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$d['user_id']]);
        Ctl::call('domain.apply', self::ctlArgs($d, $owner));
    }

    /**
     * All host names one vhost answers to: the domain, "www." for main/addon domains (unless www.<name> is a domain of its own)
     * and the parked aliases. The same list is used for the Let's Encrypt certificate.
     */
    public static function serverNames(array $d): array
    {
        $names = [$d['name']];
        if (in_array($d['type'], ['main', 'addon'], true) && !DB::one('SELECT id FROM domains WHERE name = ?', ['www.' . $d['name']])) {
            $names[] = 'www.' . $d['name'];
        }
        foreach (DB::all("SELECT name FROM domains WHERE parent_id = ? AND type = 'alias' ORDER BY name", [$d['id']]) as $a) {
            $names[] = $a['name'];
        }
        return array_values(array_unique($names));
    }
}
