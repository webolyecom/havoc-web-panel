<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB};

/**
 * Meilisearch per site: index prefix + an admin key and a search-only key limited to that prefix (see Ctl\MeiliSites).
 * The panel keeps only the key ids; the key values are read from Meilisearch when the site page is shown.
 */
final class MeiliService
{
    public static function installed(): bool
    {
        return is_file('/usr/local/bin/meilisearch') && is_file('/etc/systemd/system/meilisearch.service');
    }

    /** example.com -> example_com__ ("_" cannot occur in a domain name, so "__" ends the prefix unambiguously) */
    public static function prefix(string $domain): string
    {
        return str_replace('.', '_', strtolower($domain)) . '__';
    }

    public static function site(int $domainId): ?array
    {
        return DB::one('SELECT * FROM meili_sites WHERE domain_id = ?', [$domainId]);
    }

    public static function enable(array $actor, array $d): array
    {
        if ($old = self::site((int)$d['id'])) {
            return $old;
        }
        $prefix = self::prefix($d['name']);
        $r = Ctl::call('meili.site_enable', ['prefix' => $prefix, 'domain' => $d['name']], 60);
        DB::insert('meili_sites', ['domain_id' => $d['id'], 'prefix' => $prefix, 'admin_uid' => $r['admin_uid'], 'search_uid' => $r['search_uid']]);
        Audit::log('search.enable', $d['name'], [], $actor);
        return self::site((int)$d['id']);
    }

    public static function info(array $site): array
    {
        return Ctl::call('meili.site_info', ['prefix' => $site['prefix'], 'admin_uid' => $site['admin_uid'], 'search_uid' => $site['search_uid']], 60);
    }

    public static function rotate(array $actor, array $d, array $site): void
    {
        $r = Ctl::call('meili.site_rotate', ['prefix' => $site['prefix'], 'domain' => $d['name'], 'admin_uid' => $site['admin_uid'], 'search_uid' => $site['search_uid']], 60);
        DB::q('UPDATE meili_sites SET admin_uid = ?, search_uid = ? WHERE domain_id = ?', [$r['admin_uid'], $r['search_uid'], $d['id']]);
        Audit::log('search.rotate', $d['name'], [], $actor);
    }

    public static function disable(array $actor, array $d, bool $deleteIndexes): void
    {
        $site = self::site((int)$d['id']);
        if (!$site) {
            return;
        }
        try {
            Ctl::call('meili.site_disable', ['prefix' => $site['prefix'], 'admin_uid' => $site['admin_uid'], 'search_uid' => $site['search_uid'],
                'delete_indexes' => $deleteIndexes], 120);
        } catch (\Throwable $e) {
            if (self::installed()) {
                throw $e;
            }
        }
        DB::q('DELETE FROM meili_sites WHERE domain_id = ?', [$d['id']]);
        Audit::log('search.disable', $d['name'], ['indexes_deleted' => $deleteIndexes], $actor);
    }

    /** Prefixes of an account's sites with search, for its backups. */
    public static function prefixesFor(int $userId): array
    {
        if (!self::installed()) {
            return [];
        }
        return array_column(DB::all('SELECT m.prefix FROM meili_sites m JOIN domains d ON d.id = m.domain_id WHERE d.user_id = ?', [$userId]), 'prefix');
    }
}
