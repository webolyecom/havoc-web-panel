<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB};

/**
 * One-time upload links. The single way to put a large archive into a site without the file manager login or Claude:
 * https://<panel>/upload/<token> accepts one .zip / .tar.gz / .tgz / .tar (max 2 GB, in 8 MB chunks) for exactly one
 * site, for one hour, once. The file lands in the site's hidden ".havoc-incoming" folder (blocked from the web), from
 * where extract_archive unpacks it; old uploads are removed automatically.
 */
final class UploadService
{
    public const DIR = '.havoc-incoming';
    public const TTL = 3600;
    public const MAX = 2 * 1024 * 1024 * 1024;
    public const CHUNK = 8 * 1024 * 1024;

    /** @return array{url:string,expires:string,path_prefix:string} */
    public static function create(array $actor, array $d, string $via = 'panel'): array
    {
        $token = bin2hex(random_bytes(24));
        DB::q('DELETE FROM upload_links WHERE expires < ? AND done_at IS NULL', [time() - 86400]);
        DB::insert('upload_links', ['token_hash' => hash('sha256', $token), 'domain_id' => (int)$d['id'], 'user_id' => (int)$actor['id'],
            'via' => $via === 'claude' ? 'claude' : 'panel', 'expires' => time() + self::TTL]);
        Audit::log('upload.link', $d['name'], ['via' => $via], $actor);
        return ['url' => OAuthService::base() . '/upload/' . $token, 'expires' => gmdate('Y-m-d H:i', time() + self::TTL) . ' UTC',
            'path_prefix' => self::DIR . '/'];
    }

    /** The link row (with its domain) for a token, or null when unknown, expired or already used. */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) {
            return null;
        }
        $l = DB::one('SELECT l.*, d.name AS domain, d.user_id AS owner_id, d.status AS domain_status FROM upload_links l JOIN domains d ON d.id = l.domain_id WHERE l.token_hash = ?',
            [hash('sha256', $token)]);
        return $l && $l['done_at'] === null && (int)$l['expires'] >= time() && $l['domain_status'] === 'active' ? $l : null;
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        if (!preg_match('/\.(zip|tar\.gz|tgz|tar)$/iD', $name) || strlen($name) > 120 || $name[0] === '.') {
            throw new \InvalidArgumentException('Only .zip, .tar.gz, .tgz or .tar files (up to 120 characters in the name)');
        }
        return $name;
    }

    /** Stores one chunk; the first chunk fixes the file name, chunks must arrive in order. */
    public static function chunk(array $l, string $name, int $offset, string $data, bool $final): array
    {
        if (strlen($data) > self::CHUNK + 1024) {
            throw new \InvalidArgumentException('Chunk too large');
        }
        if ($offset === 0) {
            $name = self::cleanName($name);
            // a later upload with the same name gets a numbered name, earlier uploads are never replaced
            $name = date('Ymd-His') . '-' . $name;
            DB::q('UPDATE upload_links SET file_name = ?, received = 0 WHERE id = ?', [$name, $l['id']]);
            $l['file_name'] = $name;
            Ctl::call('fs.exec', ['account' => 'hv' . $l['owner_id'], 'domain' => $l['domain'], 'op' => 'mkdirp', 'args' => ['path' => self::DIR]], 30);
        } elseif ((string)$l['file_name'] === '' || $offset !== (int)$l['received']) {
            throw new \RuntimeException('Upload out of sync; start again');
        }
        if ($offset + strlen($data) > self::MAX) {
            throw new \RuntimeException('File too large (max 2 GB)');
        }
        $path = self::DIR . '/' . $l['file_name'];
        $r = Ctl::call('fs.exec', ['account' => 'hv' . $l['owner_id'], 'domain' => $l['domain'], 'op' => 'chunk',
            'args' => ['path' => $path, 'b64' => base64_encode($data), 'offset' => $offset, 'final' => $final]], 120);
        $size = (int)($r['size'] ?? $offset + strlen($data));
        DB::q('UPDATE upload_links SET received = ?, done_at = ? WHERE id = ?', [$size, $final ? gmdate('Y-m-d H:i:s') : null, $l['id']]);
        if ($final) {
            Audit::log('upload.done', $l['domain'], ['file' => $path, 'bytes' => $size], DB::one('SELECT * FROM users WHERE id = ?', [$l['user_id']]));
        }
        return ['received' => $size, 'path' => $path, 'done' => $final];
    }

    /** Recent uploads of a site (for Claude: what arrived and where). */
    public static function recent(int $domainId): array
    {
        return array_map(static fn($r) => ['path' => self::DIR . '/' . $r['file_name'], 'bytes' => (int)$r['received'], 'uploaded' => $r['done_at'] . ' UTC'],
            DB::all('SELECT file_name, received, done_at FROM upload_links WHERE domain_id = ? AND done_at IS NOT NULL ORDER BY id DESC LIMIT 10', [$domainId]));
    }

    /** Periodic: remove uploads older than 7 days from the sites' incoming folders. */
    public static function cleanup(): void
    {
        $rows = DB::all("SELECT DISTINCT d.id, d.name, d.user_id FROM upload_links l JOIN domains d ON d.id = l.domain_id
            WHERE l.done_at IS NOT NULL AND l.done_at < datetime('now', '-7 days') AND l.done_at > datetime('now', '-60 days')");
        foreach ($rows as $d) {
            try {
                Ctl::call('fs.exec', ['account' => 'hv' . $d['user_id'], 'domain' => $d['name'], 'op' => 'incoming_clean', 'args' => ['days' => 7]], 60);
            } catch (\Throwable) {
            }
        }
        DB::q("DELETE FROM upload_links WHERE (done_at IS NOT NULL AND done_at < datetime('now', '-60 days')) OR (done_at IS NULL AND expires < ?)", [time() - 86400]);
    }
}
