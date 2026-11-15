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
    public static function create(array $actor, int $ownerId, string $short, ?string $password = null): array
    {
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$ownerId]);
        if (!$owner || !Scope::canManageUser($actor, $owner)) {
            throw new \DomainException('Forbidden');
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
        DB::insert('db_instances', ['user_id' => $ownerId, 'name' => $name, 'db_user' => $name]);
        Audit::log('db.create', $name, [], $actor);
        return ['name' => $name, 'user' => $name, 'password' => $pass];
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
