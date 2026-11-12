<?php
declare(strict_types=1);

namespace Havoc\Core;

use PDO;

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $pdo = new PDO('sqlite:' . Config::get('db.path'), null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int
    {
        self::ident($table);
        foreach (array_keys($data) as $c) {
            self::ident($c);
        }
        $cols = implode(',', array_keys($data));
        $ph   = implode(',', array_fill(0, count($data), '?'));
        self::q("INSERT INTO $table ($cols) VALUES ($ph)", array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, int $id, array $data): void
    {
        self::ident($table);
        $set = [];
        foreach (array_keys($data) as $c) {
            self::ident($c);
            $set[] = "$c = ?";
        }
        self::q("UPDATE $table SET " . implode(',', $set) . ' WHERE id = ?', [...array_values($data), $id]);
    }

    private static function ident(string $s): void
    {
        if (!preg_match('/^[a-z_]+$/', $s)) {
            throw new \InvalidArgumentException('Invalid identifier');
        }
    }

    /** database/schema.sql = version 1; database/migrations/NNN_name.sql run in order and bump user_version. */
    public static function migrate(): void
    {
        $v = (int)self::pdo()->query('PRAGMA user_version')->fetchColumn();
        if ($v < 1) {
            self::pdo()->exec((string)file_get_contents(HAVOC_ROOT . '/database/schema.sql'));
            self::pdo()->exec('PRAGMA user_version = 1');
            $v = 1;
        }
        $files = glob(HAVOC_ROOT . '/database/migrations/*.sql') ?: [];
        sort($files);
        foreach ($files as $f) {
            $n = (int)basename($f);
            if ($n > $v) {
                self::pdo()->beginTransaction();
                self::pdo()->exec((string)file_get_contents($f));
                self::pdo()->commit();
                self::pdo()->exec('PRAGMA user_version = ' . $n);
                $v = $n;
            }
        }
    }
}
