<?php
declare(strict_types=1);

namespace Havoc\Core;

/**
 * Tenant isolation. Every query touching customer data must go through here:
 * admin -> everything, reseller -> self + own customers, user -> self only.
 */
final class Scope
{
    /** @return int[]|null null means "all users" (admin) */
    public static function userIds(array $actor): ?array
    {
        if ($actor['role'] === 'admin') {
            return null;
        }
        if ($actor['role'] === 'reseller') {
            return array_map('intval', array_column(DB::all('SELECT id FROM users WHERE id = ? OR parent_id = ?', [$actor['id'], $actor['id']]), 'id'));
        }
        return [(int)$actor['id']];
    }

    public static function canManageUser(array $actor, array $target): bool
    {
        return $actor['role'] === 'admin'
            || (int)$actor['id'] === (int)$target['id']
            || ($actor['role'] === 'reseller' && (int)$target['parent_id'] === (int)$actor['id']);
    }

    public static function domains(array $actor): array
    {
        $sql = 'SELECT d.*, u.username FROM domains d JOIN users u ON u.id = d.user_id';
        $ids = self::userIds($actor);
        if ($ids === null) {
            return DB::all($sql . ' ORDER BY d.name');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return DB::all("$sql WHERE d.user_id IN ($in) ORDER BY d.name", $ids);
    }

    public static function domain(array $actor, int $id): ?array
    {
        $d = DB::one('SELECT d.*, u.username FROM domains d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$id]);
        if (!$d) {
            return null;
        }
        $ids = self::userIds($actor);
        return ($ids === null || in_array((int)$d['user_id'], $ids, true)) ? $d : null;
    }

    public static function users(array $actor): array
    {
        if ($actor['role'] === 'admin') {
            return DB::all('SELECT id, parent_id, role, username, email, status, package_id, created_at FROM users ORDER BY id');
        }
        return DB::all('SELECT id, parent_id, role, username, email, status, package_id, created_at FROM users WHERE parent_id = ? ORDER BY id', [$actor['id']]);
    }

    /** Generic tenant-checked row fetch for tables that carry user_id. */
    public static function row(array $actor, string $table, int $id): ?array
    {
        if (!in_array($table, ['db_instances', 'ftp_accounts', 'cron_jobs', 'backups', 'backup_targets', 'backup_jobs', 'packages'], true)) {
            throw new \InvalidArgumentException('Table not allowed');
        }
        $col = $table === 'packages' ? 'owner_id' : 'user_id';
        $r = DB::one("SELECT * FROM $table WHERE id = ?", [$id]);
        if (!$r) {
            return null;
        }
        $ids = self::userIds($actor);
        return ($ids === null || in_array((int)$r[$col], $ids, true)) ? $r : null;
    }
}
