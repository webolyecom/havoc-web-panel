<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Audit
{
    public static function log(string $action, string $target = '', array $meta = [], ?array $user = null): void
    {
        $user ??= Auth::user();
        DB::insert('audit_log', [
            'user_id'  => $user['id'] ?? null,
            'username' => $user['username'] ?? null,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? 'cli',
            'action'   => $action,
            'target'   => $target,
            'meta'     => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
