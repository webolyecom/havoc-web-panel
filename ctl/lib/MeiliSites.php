<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Meilisearch per site: every site gets an index prefix (example_com__) and two API keys limited to "<prefix>*":
 * an admin key (indexes, documents, settings, tasks) for the site's server code and a search-only key.
 * The prefix ends with "__", which a domain name cannot produce ("_" is not allowed in domain names), so the
 * prefix of example.com can never match the indexes of example.com.tr.
 * Also exports / imports a site's indexes (settings + documents) for backups and site transfers.
 */
final class MeiliSites
{
    private const URL = 'http://127.0.0.1:7700';
    private const ADMIN = ['search', 'documents.*', 'indexes.*', 'settings.*', 'tasks.get', 'stats.get'];

    private static function prefix(mixed $p): string
    {
        if (!is_string($p) || !preg_match('/^[a-z0-9-]+(_[a-z0-9-]+)*__$/D', $p) || strlen($p) > 200) {
            throw new \InvalidArgumentException('Invalid index prefix');
        }
        return $p;
    }

    private static function uid(mixed $u): string
    {
        if (!is_string($u) || !preg_match('/^[a-f0-9-]{36}$/D', $u)) {
            throw new \InvalidArgumentException('Invalid key id');
        }
        return $u;
    }

    /** @return array{0:int,1:mixed} [HTTP status, decoded JSON] */
    private static function api(string $method, string $path, mixed $body = null, ?string $raw = null, string $type = 'application/json'): array
    {
        $key = (string)(Stack::meiliConf()['master_key'] ?? '');
        if ($key === '') {
            throw new \RuntimeException('Meilisearch is not installed');
        }
        $ch = curl_init(self::URL . $path);
        $h = ['Authorization: Bearer ' . $key];
        $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*'];
        if ($body !== null || $raw !== null) {
            $opt[CURLOPT_POSTFIELDS] = $raw ?? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $h[] = 'Content-Type: ' . $type;
        }
        $opt[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opt);
        $res = curl_exec($ch);
        if ($res === false) {
            throw new \RuntimeException('Meilisearch is not reachable: ' . curl_error($ch));
        }
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string)$res, true), (string)$res];
    }

    private static function ok(array $r, string $what): mixed
    {
        if ($r[0] >= 300) {
            throw new \RuntimeException("Meilisearch ($what): " . (is_array($r[1]) ? ($r[1]['message'] ?? 'error') : 'HTTP ' . $r[0]));
        }
        return $r[1];
    }

    private static function createKeys(string $prefix, string $domain): array
    {
        $a = self::ok(self::api('POST', '/keys', ['name' => "Havoc $domain (admin)", 'description' => "Havoc WEB Panel: indexes $prefix* of $domain",
            'actions' => self::ADMIN, 'indexes' => [$prefix . '*'], 'expiresAt' => null]), 'create key');
        $s = self::ok(self::api('POST', '/keys', ['name' => "Havoc $domain (search)", 'description' => "Havoc WEB Panel: search in $prefix* of $domain",
            'actions' => ['search'], 'indexes' => [$prefix . '*'], 'expiresAt' => null]), 'create key');
        return ['admin_uid' => $a['uid'], 'admin_key' => $a['key'], 'search_uid' => $s['uid'], 'search_key' => $s['key']];
    }

    public static function enable(array $a): array
    {
        return self::createKeys(self::prefix($a['prefix'] ?? ''), Validator::domain((string)($a['domain'] ?? '')));
    }

    private static function indexes(string $prefix): array
    {
        $out = [];
        $offset = 0;
        do {
            $r = self::ok(self::api('GET', '/indexes?limit=200&offset=' . $offset), 'list indexes');
            foreach ($r['results'] ?? [] as $i) {
                if (str_starts_with((string)$i['uid'], $prefix)) {
                    $out[$i['uid']] = ['uid' => $i['uid'], 'primaryKey' => $i['primaryKey'] ?? null];
                }
            }
            $offset += 200;
        } while ($offset < (int)($r['total'] ?? 0));
        return $out;
    }

    /** Keys (their values), the site's indexes with document counts and sizes. */
    public static function info(array $a): array
    {
        $prefix = self::prefix($a['prefix'] ?? '');
        $keys = [];
        foreach (['admin' => $a['admin_uid'] ?? '', 'search' => $a['search_uid'] ?? ''] as $k => $uid) {
            $r = self::api('GET', '/keys/' . self::uid($uid));
            $keys[$k] = $r[0] === 200 ? (string)$r[1]['key'] : null;
        }
        $stats = self::ok(self::api('GET', '/stats'), 'stats');
        $idx = [];
        $total = 0;
        foreach (self::indexes($prefix) as $uid => $i) {
            $s = $stats['indexes'][$uid] ?? [];
            $size = (int)($s['rawDocumentDbSize'] ?? 0);
            $total += $size;
            $idx[] = ['uid' => $uid, 'documents' => (int)($s['numberOfDocuments'] ?? 0), 'size' => $size, 'indexing' => !empty($s['isIndexing'])];
        }
        return ['keys' => $keys, 'indexes' => $idx, 'size' => $total];
    }

    /** Sizes of all sites (for the notification rule): prefix => bytes. */
    public static function sizes(array $a): array
    {
        $stats = self::ok(self::api('GET', '/stats'), 'stats');
        $out = [];
        foreach ((array)($a['prefixes'] ?? []) as $p) {
            $p = self::prefix($p);
            $out[$p] = 0;
            foreach ($stats['indexes'] ?? [] as $uid => $s) {
                if (str_starts_with((string)$uid, $p)) {
                    $out[$p] += (int)($s['rawDocumentDbSize'] ?? 0);
                }
            }
        }
        return ['sizes' => $out];
    }

    /** New key values (the old ones stop working at once). */
    public static function rotate(array $a): array
    {
        $prefix = self::prefix($a['prefix'] ?? '');
        foreach (['admin_uid', 'search_uid'] as $k) {
            if (!empty($a[$k])) {
                self::api('DELETE', '/keys/' . self::uid($a[$k]));
            }
        }
        return self::createKeys($prefix, Validator::domain((string)($a['domain'] ?? '')));
    }

    /** Removes the keys; the indexes too when asked (site deleted, or the user chose so). */
    public static function disable(array $a): array
    {
        $prefix = self::prefix($a['prefix'] ?? '');
        foreach (['admin_uid', 'search_uid'] as $k) {
            if (!empty($a[$k])) {
                self::api('DELETE', '/keys/' . self::uid($a[$k]));
            }
        }
        $n = 0;
        if (!empty($a['delete_indexes'])) {
            foreach (self::indexes($prefix) as $uid => $_) {
                self::api('DELETE', '/indexes/' . rawurlencode($uid));
                $n++;
            }
        }
        return ['deleted_indexes' => $n];
    }

    /**
     * Writes the site's indexes into $dir/<uid>/{index.json, settings.json, documents.ndjson}.
     * Called by the backup and transfer exports (as root; the files are handed to the account or the panel afterwards).
     */
    public static function export(string $prefix, string $dir): int
    {
        $prefix = self::prefix($prefix);
        $n = 0;
        foreach (self::indexes($prefix) as $uid => $i) {
            $d = "$dir/$uid";
            is_dir($d) || mkdir($d, 0700, true);
            file_put_contents("$d/index.json", json_encode(['uid' => $uid, 'primaryKey' => $i['primaryKey']]));
            // kept as received: decoding into PHP arrays would turn empty objects ({}) into lists ([])
            $r = self::api('GET', '/indexes/' . rawurlencode($uid) . '/settings');
            self::ok($r, 'settings');
            file_put_contents("$d/settings.json", $r[2]);
            $fh = fopen("$d/documents.ndjson", 'wb');
            $offset = 0;
            do {
                $r = self::ok(self::api('POST', '/indexes/' . rawurlencode($uid) . '/documents/fetch', ['offset' => $offset, 'limit' => 1000]), 'documents');
                foreach ($r['results'] ?? [] as $doc) {
                    fwrite($fh, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                }
                $offset += 1000;
            } while ($offset < (int)($r['total'] ?? 0));
            fclose($fh);
            $n++;
        }
        return $n;
    }

    /**
     * Recreates indexes from an export. Only indexes under $from are taken; they are renamed to $to (same prefix
     * on a restore, the new site's prefix on a transfer). Existing indexes of the same name are replaced.
     */
    public static function import(string $dir, string $from, string $to): array
    {
        $from = self::prefix($from);
        $to = self::prefix($to);
        $done = [];
        foreach (glob("$dir/*/index.json") ?: [] as $f) {
            $d0 = dirname($f);
            // content from an archive: never follow links (root would read files the archive's author cannot)
            if (is_link($d0) || is_link($f) || is_link("$d0/settings.json") || is_link("$d0/documents.ndjson")) {
                continue;
            }
            $meta = json_decode((string)file_get_contents($f), true);
            $uid = (string)($meta['uid'] ?? '');
            if (!str_starts_with($uid, $from) || !preg_match('/^[A-Za-z0-9_-]{1,400}$/D', $uid)) {
                continue;
            }
            $new = $to . substr($uid, strlen($from));
            $d = dirname($f);
            self::api('DELETE', '/indexes/' . rawurlencode($new));
            self::waitIdle($new);
            $pk = is_string($meta['primaryKey'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $meta['primaryKey']) ? $meta['primaryKey'] : null;
            self::ok(self::api('POST', '/indexes', ['uid' => $new, 'primaryKey' => $pk]), 'create index');
            $settings = (string)@file_get_contents("$d/settings.json");
            if (json_decode($settings) instanceof \stdClass) {
                self::ok(self::api('PATCH', '/indexes/' . rawurlencode($new) . '/settings', null, $settings), 'settings');
            }
            // documents in batches of ~20 MB (Meilisearch accepts 100 MB per request by default)
            $fh = @fopen("$d/documents.ndjson", 'rb');
            $docs = 0;
            $buf = '';
            while ($fh && ($line = fgets($fh)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $buf .= $line;
                $docs++;
                if (strlen($buf) > 20 * 1048576) {
                    self::ok(self::api('POST', '/indexes/' . rawurlencode($new) . '/documents', null, $buf, 'application/x-ndjson'), 'documents');
                    $buf = '';
                }
            }
            if ($buf !== '') {
                self::ok(self::api('POST', '/indexes/' . rawurlencode($new) . '/documents', null, $buf, 'application/x-ndjson'), 'documents');
            }
            $fh && fclose($fh);
            $done[] = ['uid' => $new, 'documents' => $docs];
        }
        return $done;
    }

    /** Waits (up to 30 s) until queued tasks of an index are processed, so a deleted index can be created again. */
    private static function waitIdle(string $uid): void
    {
        for ($i = 0; $i < 60; $i++) {
            $r = self::api('GET', '/tasks?indexUids=' . rawurlencode($uid) . '&statuses=enqueued,processing&limit=1');
            if ($r[0] !== 200 || (int)($r[1]['total'] ?? count($r[1]['results'] ?? [])) === 0) {
                return;
            }
            usleep(500000);
        }
    }

    /**
     * Moves an unpacked folder (written by an unprivileged user) under a root-only directory, so nobody can change it
     * while root reads it. Returns the new path.
     */
    public static function lockIn(string $dir): string
    {
        $safe = '/var/tmp/havoc-mr-' . bin2hex(random_bytes(6));
        mkdir($safe, 0700);
        if (!@rename($dir, "$safe/data")) {
            throw new \RuntimeException('Could not secure the unpacked search data');
        }
        return "$safe/data";
    }

    /** Transfer: the import packaged as meili.tar.gz in the staging folder. */
    public static function importArchive(array $a): array
    {
        $id = (string)($a['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid transfer id');
        }
        $file = "/var/backups/havoc/incoming/$id/meili.tar.gz";
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException('Search data missing');
        }
        $w = '/var/tmp/havoc-ms-' . bin2hex(random_bytes(6));
        mkdir($w, 0700);
        try {
            // extracted by an unprivileged system user, not root
            chown($w, 'nobody');
            [$c, $e] = Sys::runIO(['setpriv', '--reuid', 'nobody', '--regid', 'nogroup', '--clear-groups', '--no-new-privs',
                is_file('/usr/bin/tar') ? '/usr/bin/tar' : '/bin/tar', '-xzf', '-', '-C', $w, '--no-same-owner', '--no-same-permissions'], $file, null);
            if ($c > 1) {
                throw new \RuntimeException('Unpacking search data failed: ' . mb_substr($e, 0, 200));
            }
            $safe = self::lockIn($w);
            try {
                return ['indexes' => self::import($safe, (string)($a['from'] ?? ''), (string)($a['to'] ?? ''))];
            } finally {
                Sys::run(['rm', '-rf', '--', dirname($safe)]);
            }
        } finally {
            Sys::run(['rm', '-rf', '--', $w]);
        }
    }
}
