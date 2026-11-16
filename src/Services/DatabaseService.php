<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB, Scope};

/** MariaDB databases of hosting accounts (one database = one user of the same name). */
final class DatabaseService
{
    public static function password(): string
    {
        return substr(strtr(base64_encode(random_bytes(24)), '+/=', '-_.'), 0, 24);
    }

    /**
     * @return array{name:string,user:string,password:string}
     * @throws \DomainException db.invalid | db.quota | db.exists
     */
    public static function create(array $actor, int $ownerId, string $short, ?string $password = null, ?int $domainId = null): array
    {
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$ownerId]);
        if (!$owner || !Scope::canManageUser($actor, $owner)) {
            throw new \DomainException('Forbidden');
        }
        if ($domainId !== null && !self::siteOf($ownerId, $domainId)) {
            throw new \DomainException('db.site_invalid');
        }
        $short = strtolower(trim($short));
        if (!preg_match('/^[a-z0-9_]{1,16}$/D', $short)) {
            throw new \DomainException('db.invalid');
        }
        if ($owner['package_id']) {
            $max = (int)DB::q('SELECT max_databases FROM packages WHERE id = ?', [$owner['package_id']])->fetchColumn();
            if ((int)DB::q('SELECT COUNT(*) FROM db_instances WHERE user_id = ?', [$ownerId])->fetchColumn() >= $max) {
                throw new \DomainException('db.quota');
            }
        }
        $name = 'hv' . $ownerId . '_' . $short;
        if (DB::one('SELECT id FROM db_instances WHERE name = ?', [$name])) {
            throw new \DomainException('db.exists');
        }
        $pass = $password !== null && $password !== '' ? $password : self::password();
        Ctl::call('db.create', ['name' => $name, 'password' => $pass]);
        DB::insert('db_instances', ['user_id' => $ownerId, 'name' => $name, 'db_user' => $name, 'domain_id' => $domainId]);
        Audit::log('db.create', $name, [], $actor);
        return ['name' => $name, 'user' => $name, 'password' => $pass];
    }

    /** A site the database can belong to: same owner, not an alias. */
    private static function siteOf(int $ownerId, int $domainId): ?array
    {
        return DB::one("SELECT * FROM domains WHERE id = ? AND user_id = ? AND type != 'alias'", [$domainId, $ownerId]);
    }

    /** Assigns a database to one of its owner's sites (or to none). */
    public static function assign(array $actor, array $db, ?int $domainId): void
    {
        if ($domainId !== null && !self::siteOf((int)$db['user_id'], $domainId)) {
            throw new \DomainException('db.site_invalid');
        }
        DB::q('UPDATE db_instances SET domain_id = ? WHERE id = ?', [$domainId, $db['id']]);
        Audit::log('db.site', $db['name'], ['domain_id' => $domainId], $actor);
    }

    /**
     * Assigns unassigned databases by looking into the sites' config files (wp-config.php, .env, ...): a database is
     * assigned when exactly one site of its owner mentions it. Returns [db name => domain name].
     */
    public static function detect(array $actor): array
    {
        $ids = Scope::userIds($actor);
        $users = $ids === null ? array_column(DB::all('SELECT DISTINCT user_id FROM db_instances WHERE domain_id IS NULL'), 'user_id') : $ids;
        $found = [];
        foreach ($users as $uid) {
            $dbs = array_column(DB::all('SELECT name FROM db_instances WHERE user_id = ? AND domain_id IS NULL', [$uid]), 'name');
            $sites = DB::all("SELECT id, name FROM domains WHERE user_id = ? AND type != 'alias'", [$uid]);
            if (!$dbs || !$sites) {
                continue;
            }
            $items = array_map(static fn($d) => ['account' => 'hv' . $uid, 'domain' => $d['name'], 'dbs' => $dbs], $sites);
            $sizes = Ctl::call('transfer.sizes', ['items' => $items], 600)['sizes'] ?? [];
            foreach ($dbs as $n) {
                $hits = array_values(array_filter($sites, static fn($d) => !empty($sizes[$d['name']]['dbs'][$n]['used'])));
                if (count($hits) === 1) {
                    DB::q('UPDATE db_instances SET domain_id = ? WHERE name = ?', [$hits[0]['id'], $n]);
                    $found[$n] = $hits[0]['name'];
                }
            }
        }
        if ($found) {
            Audit::log('db.detect', implode(',', array_keys($found)), [], $actor);
        }
        return $found;
    }

    /** A free short name like "wp", "wp2", ... for an owner. */
    public static function freeName(int $ownerId, string $base): string
    {
        for ($i = 1; $i < 1000; $i++) {
            $short = $i === 1 ? $base : $base . $i;
            if (!DB::one('SELECT id FROM db_instances WHERE name = ?', ['hv' . $ownerId . '_' . $short])) {
                return $short;
            }
        }
        return $base . bin2hex(random_bytes(3));
    }
}
