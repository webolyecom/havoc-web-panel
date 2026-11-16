<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Config, Crypt, Ctl, DB};

/**
 * Site transfer between two Havoc panels.
 *
 * The source panel creates a transfer key: one string with its address, a secret and the public-key pin of its
 * certificate ("hvt1." + base64url JSON). The destination pastes it, lists the allowed sites and pulls each one:
 * export on the source (files, databases, settings, optionally the certificate), chunked download with SHA-256
 * checks, then import here as the receiving hosting account. Nothing is ever deleted on the source.
 */
final class TransferService
{
    public const PREFIX = 'hvt1.';
    private const CHUNK = 32 * 1048576;
    private const SYSTEM = ['id' => null, 'username' => 'system', 'role' => 'admin'];

    // ==== source side ===============================================================================================

    /** @return array{0:string,1:int} [transfer key to show once, key id] */
    public static function createKey(array $actor, string $label, ?array $domainIds, ?string $ip, int $hours, string $baseUrl): array
    {
        $secret = bin2hex(random_bytes(32));
        $id = DB::insert('transfer_keys', ['label' => mb_substr($label, 0, 80), 'token_hash' => hash('sha256', $secret),
            'domains' => $domainIds === null ? null : json_encode(array_values(array_map('intval', $domainIds))),
            'allowed_ip' => $ip ?: null, 'expires_at' => time() + max(1, min(168, $hours)) * 3600, 'created_by' => $actor['id']]);
        $key = self::PREFIX . rtrim(strtr(base64_encode(json_encode(['u' => $baseUrl, 'k' => $secret, 'p' => self::ownPin($baseUrl)], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        Audit::log('transfer.key', $label, ['domains' => $domainIds === null ? 'all' : count($domainIds), 'hours' => $hours], $actor);
        return [$key, $id];
    }

    /** Public-key pin (sha256 of the SubjectPublicKeyInfo, base64) of the certificate this panel serves at $url. */
    public static function ownPin(string $url): ?string
    {
        $u = parse_url($url);
        if (($u['scheme'] ?? '') !== 'https') {
            return null;
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
            'peer_name' => $u['host'], 'SNI_enabled' => true]]);
        $s = @stream_socket_client('ssl://127.0.0.1:' . ($u['port'] ?? 443), $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
        $cert = $s ? (stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null) : null;
        return $cert ? self::pin($cert) : null;
    }

    public static function pin(mixed $cert): ?string
    {
        $k = @openssl_pkey_get_public($cert);
        $pem = $k ? (openssl_pkey_get_details($k)['key'] ?? '') : '';
        if (!preg_match('/-----BEGIN PUBLIC KEY-----(.+)-----END PUBLIC KEY-----/s', $pem, $m)) {
            return null;
        }
        return base64_encode(hash('sha256', base64_decode(preg_replace('/\s+/', '', $m[1])), true));
    }

    public static function authenticate(?string $bearer, string $ip): ?array
    {
        if (!$bearer || !preg_match('/^[a-f0-9]{64}$/D', $bearer)) {
            return null;
        }
        $k = DB::one('SELECT * FROM transfer_keys WHERE token_hash = ? AND revoked = 0', [hash('sha256', $bearer)]);
        if (!$k || (int)$k['expires_at'] < time() || ($k['allowed_ip'] && $k['allowed_ip'] !== $ip)) {
            return null;
        }
        DB::q('UPDATE transfer_keys SET last_used = CURRENT_TIMESTAMP, last_ip = ? WHERE id = ?', [$ip, $k['id']]);
        return $k;
    }

    /** Sites this key may copy: main, addon and subdomains (aliases and redirects travel with their site). */
    public static function allowedDomains(array $key): array
    {
        $ids = $key['domains'] === null ? null : (json_decode((string)$key['domains'], true) ?: []);
        $rows = DB::all("SELECT d.*, u.username, u.email FROM domains d JOIN users u ON u.id = d.user_id WHERE d.type != 'alias' AND d.status = 'active' ORDER BY d.name");
        return array_values(array_filter($rows, static fn($d) => $ids === null || in_array((int)$d['id'], $ids, true)));
    }

    public static function listing(array $key): array
    {
        $doms = self::allowedDomains($key);
        $items = [];
        foreach ($doms as $d) {
            $items[] = ['account' => 'hv' . $d['user_id'], 'domain' => $d['name'],
                'dbs' => array_column(DB::all('SELECT name FROM db_instances WHERE user_id = ? ORDER BY name', [$d['user_id']]), 'name')];
        }
        $sizes = $items ? (Ctl::call('transfer.sizes', ['items' => $items], 600)['sizes'] ?? []) : [];
        $out = [];
        foreach ($doms as $d) {
            $s = $sizes[$d['name']] ?? ['size' => 0, 'dbs' => []];
            $parent = $d['parent_id'] ? DB::one('SELECT name FROM domains WHERE id = ?', [$d['parent_id']]) : null;
            $out[] = ['id' => (int)$d['id'], 'name' => $d['name'], 'type' => $d['type'], 'parent' => $parent['name'] ?? null, 'owner' => $d['username'],
                'php' => $d['php_version'], 'ssl' => $d['ssl_mode'], 'size' => (int)$s['size'],
                'aliases' => array_column(DB::all("SELECT name FROM domains WHERE parent_id = ? AND type = 'alias'", [$d['id']]), 'name'),
                'dbs' => array_map(static fn($n, $x) => ['name' => $n, 'size' => (int)$x['size'], 'used' => (bool)$x['used']], array_keys($s['dbs']), $s['dbs'])];
        }
        return $out;
    }

    public static function startExport(array $key, int $domainId, array $dbs, bool $ssl): string
    {
        $d = null;
        foreach (self::allowedDomains($key) as $x) {
            if ((int)$x['id'] === $domainId) {
                $d = $x;
            }
        }
        if (!$d) {
            throw new \DomainException('This site is not included in the transfer key');
        }
        $own = array_column(DB::all('SELECT name FROM db_instances WHERE user_id = ?', [$d['user_id']]), 'name');
        $dbs = array_values(array_intersect($own, $dbs));
        $id = bin2hex(random_bytes(16));
        DB::insert('transfer_exports', ['id' => $id, 'key_id' => $key['id'], 'domain_id' => $d['id'], 'options' => json_encode(['dbs' => $dbs, 'ssl' => $ssl]),
            'created_at' => time()]);
        Audit::log('transfer.export', $d['name'], ['dbs' => count($dbs), 'from' => $key['last_ip']], ['id' => $key['created_by'], 'username' => 'transfer']);
        BackupService::spawn('transfer:export', $id);
        return $id;
    }

    /** CLI: builds the export package (root helper) and the manifest the destination needs. */
    public static function runExport(string $id): void
    {
        $x = DB::one('SELECT * FROM transfer_exports WHERE id = ?', [$id]);
        if (!$x || $x['status'] !== 'running') {
            return;
        }
        try {
            $d = DB::one('SELECT d.*, u.username, u.email, u.password AS pw FROM domains d JOIN users u ON u.id = d.user_id WHERE d.id = ?', [$x['domain_id']]);
            if (!$d) {
                throw new \RuntimeException('Site no longer exists');
            }
            $o = json_decode((string)$x['options'], true) ?: [];
            $r = Ctl::call('transfer.export', ['id' => $id, 'account' => 'hv' . $d['user_id'], 'domain' => $d['name'], 'dbs' => $o['dbs'] ?? [],
                'ssl' => !empty($o['ssl']) && $d['ssl_mode'] !== 'none', 'ssl_mode' => $d['ssl_mode']], 7200);
            $settings = array_intersect_key($d, array_flip(['php_version', 'php_extensions', 'opcache_enabled', 'opcache_memory', 'force_https', 'hsts', 'waf',
                'logs_enabled', 'php_ini', 'php_ini_admin', 'nginx_custom', 'ssl_mode']));
            $parent = $d['parent_id'] ? DB::one('SELECT name FROM domains WHERE id = ?', [$d['parent_id']]) : null;
            $manifest = ['format' => 1, 'domain' => $d['name'], 'type' => $d['type'], 'parent' => $parent['name'] ?? null,
                'home' => "/home/hv{$d['user_id']}/domains/{$d['name']}", 'settings' => $settings,
                'owner' => ['username' => $d['username'], 'email' => $d['email'], 'password' => $d['pw']],
                'aliases' => array_column(DB::all("SELECT name FROM domains WHERE parent_id = ? AND type = 'alias'", [$d['id']]), 'name'),
                'redirects' => DB::all('SELECT source, target, code FROM redirects WHERE domain_id = ?', [$d['id']]),
                'cron' => DB::all('SELECT label, schedule, command, enabled, log_output FROM cron_jobs WHERE domain_id = ?', [$d['id']]),
                'databases' => array_map(static fn($n) => ['name' => $n, 'hash' => $r['db_users'][$n] ?? null], $o['dbs'] ?? []),
                'source' => ['version' => UpdateService::current(), 'host' => gethostname() ?: '']];
            DB::q("UPDATE transfer_exports SET status = 'ready', files = ?, manifest = ? WHERE id = ?", [json_encode($r['files']), json_encode($manifest), $id]);
        } catch (\Throwable $e) {
            DB::q("UPDATE transfer_exports SET status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $id]);
        }
    }

    /** Removes export packages after a day (and their rows); keys expire on their own. */
    public static function cleanup(): void
    {
        foreach (DB::all('SELECT id FROM transfer_exports WHERE created_at < ?', [time() - 86400]) as $x) {
            try {
                Ctl::call('transfer.export_delete', ['id' => $x['id']]);
            } catch (\Throwable) {
            }
            DB::q('DELETE FROM transfer_exports WHERE id = ?', [$x['id']]);
        }
    }

    // ==== destination side ==========================================================================================

    /** @return array{u:string,k:string,p:?string} */
    public static function parseKey(string $key): array
    {
        $key = trim($key);
        if (!str_starts_with($key, self::PREFIX)) {
            throw new \InvalidArgumentException(t('transfer.e_key'));
        }
        $b = substr($key, strlen(self::PREFIX));
        $j = json_decode((string)base64_decode(strtr($b, '-_', '+/') . str_repeat('=', (4 - strlen($b) % 4) % 4), true), true);
        $allowHttp = (bool)Config::get('transfer.allow_http', false);            // test setups only
        if (!is_array($j) || !is_string($j['u'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', (string)($j['k'] ?? ''))
            || !preg_match($allowHttp ? '#^https?://[A-Za-z0-9.\-\[\]:]+$#D' : '#^https://[A-Za-z0-9.\-\[\]:]+$#D', $j['u'])
            || (isset($j['p']) && !preg_match('#^[A-Za-z0-9+/]{43}=$#D', (string)$j['p']))) {
            throw new \InvalidArgumentException(t('transfer.e_key'));
        }
        return ['u' => rtrim($j['u'], '/'), 'k' => $j['k'], 'p' => $j['p'] ?? null];
    }

    /** HTTP call to the source panel. $to = file to append the body to (downloads). */
    public static function call(array $src, string $method, string $path, ?array $body = null, ?string $to = null, ?string $range = null): array
    {
        $ch = curl_init($src['u'] . $path);
        $h = ['Authorization: Bearer ' . $src['k'], 'Accept: application/json'];
        if ($range) {
            $h[] = 'Range: bytes=' . $range;
        }
        $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $to ? 900 : 120,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ((bool)Config::get('transfer.allow_http', false) ? CURLPROTO_HTTP : 0)];
        if ($src['p']) {
            // the source's own certificate, pinned by its public key (works for IP addresses and self-signed certificates)
            $opt += [CURLOPT_PINNEDPUBLICKEY => 'sha256//' . $src['p'], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0];
        }
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = json_encode($body);
            $h[] = 'Content-Type: application/json';
            $opt[CURLOPT_HTTPHEADER] = $h;
        }
        $fh = null;
        if ($to) {
            $fh = fopen($to, 'ab');
            $opt[CURLOPT_FILE] = $fh;
        } else {
            $opt[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opt);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        $fh && fclose($fh);
        if ($res === false) {
            throw new \RuntimeException(t('transfer.e_connect') . ': ' . $err);
        }
        if ($to) {
            if ($code !== 206 && $code !== 200) {
                throw new \RuntimeException("Download failed (HTTP $code)");
            }
            return ['code' => $code];
        }
        $j = json_decode((string)$res, true);
        if ($code >= 400 || !is_array($j)) {
            throw new \RuntimeException(($j['error'] ?? null) ? (string)$j['error'] : t('transfer.e_connect') . " (HTTP $code)");
        }
        return $j;
    }

    private static function src(array $t): array
    {
        return ['u' => $t['source_url'], 'k' => (string)Crypt::decrypt($t['key_enc']), 'p' => $t['fingerprint'] ?: null];
    }

    /** Connects with a key, stores the transfer and the list of sites. */
    public static function connect(array $actor, string $key): int
    {
        $src = self::parseKey($key);
        $hello = self::call($src, 'GET', '/transfer/v1/hello');
        if (($hello['panel'] ?? '') !== 'havoc') {
            throw new \RuntimeException(t('transfer.e_not_havoc'));
        }
        $list = self::call($src, 'GET', '/transfer/v1/domains');
        $id = DB::insert('transfers', ['source_url' => $src['u'], 'key_enc' => Crypt::encrypt($src['k']), 'fingerprint' => $src['p'],
            'source_info' => json_encode(['hello' => $hello, 'domains' => $list['domains'] ?? []]), 'created_by' => $actor['id']]);
        Audit::log('transfer.connect', $src['u'], [], $actor);
        return $id;
    }

    /** Checks for the selection page: what is already here, what is missing. */
    public static function preflight(array $domains): array
    {
        $php = DomainService::phpVersions();
        $free = (int)@disk_free_space('/home');
        $out = [];
        foreach ($domains as $d) {
            $notes = [];
            $block = null;
            if (DB::one('SELECT id FROM domains WHERE name = ?', [$d['name']])) {
                $block = t('transfer.n_exists');
            }
            foreach ($d['aliases'] as $a) {
                if (DB::one('SELECT id FROM domains WHERE name = ?', [$a])) {
                    $notes[] = t('transfer.n_alias_exists', ['name' => $a]);
                }
            }
            if ($d['type'] === 'sub' && $d['parent'] && !DB::one('SELECT id FROM domains WHERE name = ?', [$d['parent']])) {
                $notes[] = t('transfer.n_parent', ['name' => $d['parent']]);
            }
            if (!in_array($d['php'], $php, true)) {
                $notes[] = t('transfer.n_php', ['v' => $d['php'], 'use' => self::nearestPhp($d['php'], $php)]);
            }
            $need = $d['size'] * 2 + array_sum(array_column($d['dbs'], 'size')) * 3;
            if ($need > $free) {
                $notes[] = t('transfer.n_disk');
            }
            $u = DB::one('SELECT id, role FROM users WHERE username = ?', [$d['owner']]);
            $out[$d['name']] = ['block' => $block, 'notes' => $notes, 'owner_exists' => (bool)$u];
        }
        return $out;
    }

    private static function nearestPhp(string $want, array $have): string
    {
        if (!$have) {
            return $want;
        }
        usort($have, static fn($a, $b) => abs((float)$a - (float)$want) <=> abs((float)$b - (float)$want) ?: version_compare($b, $a));
        return $have[0];
    }

    /** Queues the selected sites and starts the background run. */
    public static function start(int $transferId, array $items): void
    {
        foreach ($items as $it) {
            DB::insert('transfer_items', ['transfer_id' => $transferId, 'domain' => $it['domain'], 'options' => json_encode($it['options'])]);
        }
        BackupService::spawn('transfer:run', (string)$transferId);
    }

    /** CLI: imports the queued sites of a transfer one after the other (subdomains after their parent). */
    public static function run(int $transferId): void
    {
        $lock = fopen(dirname((string)Config::get('db.path')) . "/transfer-$transferId.lock", 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return;
        }
        try {
            $t = DB::one('SELECT * FROM transfers WHERE id = ?', [$transferId]);
            // log lines in the language of the administrator who started the transfer
            $loc = $t ? DB::q('SELECT locale FROM users WHERE id = ?', [$t['created_by']])->fetchColumn() : false;
            \Havoc\Core\I18n::use($loc ?: (string)Config::get('app.locale', 'en'));
            while ($t && ($it = DB::one("SELECT * FROM transfer_items WHERE transfer_id = ? AND status = 'queued'
                    ORDER BY CASE WHEN json_extract(options, '$.type') = 'sub' THEN 1 ELSE 0 END, id LIMIT 1", [$transferId]))) {
                self::runItem($t, $it);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function progress(int $id, string $step, int $pct, ?string $line = null): void
    {
        DB::q('UPDATE transfer_items SET step = ?, progress = ?, log = log || ? WHERE id = ?',
            [$step, $pct, $line === null ? '' : '[' . gmdate('H:i:s') . '] ' . $line . "\n", $id]);
    }

    private static function runItem(array $t, array $it): void
    {
        $id = (int)$it['id'];
        $o = json_decode((string)$it['options'], true) ?: [];
        DB::q("UPDATE transfer_items SET status = 'running', log = '', started_at = CURRENT_TIMESTAMP WHERE id = ?", [$id]);
        $src = self::src($t);
        $stage = null;
        $exportId = null;
        $result = ['warnings' => []];
        $created = ['domain' => null, 'dbs' => [], 'user' => null];   // rolled back if the site does not arrive complete
        try {
            // 1. export on the source
            self::progress($id, 'export', 2, t('transfer.l_export'));
            $exportId = (string)self::call($src, 'POST', '/transfer/v1/exports', ['domain_id' => (int)$o['source_id'], 'dbs' => $o['dbs'] ?? [], 'ssl' => !empty($o['ssl'])])['id'];
            $deadline = time() + 7200;
            do {
                sleep(2);
                $x = self::call($src, 'GET', "/transfer/v1/exports/$exportId");
                if ($x['status'] === 'failed') {
                    throw new \RuntimeException(t('transfer.e_export') . ': ' . ($x['error'] ?? ''));
                }
            } while ($x['status'] !== 'ready' && time() < $deadline);
            if ($x['status'] !== 'ready') {
                throw new \RuntimeException(t('transfer.e_export') . ': timeout');
            }
            $m = $x['manifest'];
            if (($m['domain'] ?? '') !== $it['domain']) {
                throw new \RuntimeException('Unexpected export');
            }
            // 2. download, resumable, verified
            $stage = Ctl::call('transfer.stage', ['id' => $exportId])['dir'];
            $total = max(1, array_sum(array_column($x['files'], 'size')));
            $done = 0;
            foreach ($x['files'] as $f) {
                if (!preg_match('/^(files\.tar\.gz|ssl\.json|db-[a-z0-9_]{1,40}\.sql\.gz)$/D', (string)$f['name'])) {
                    throw new \RuntimeException('Unexpected file in export');
                }
                $path = "$stage/{$f['name']}";
                @unlink($path);
                $size = (int)$f['size'];
                $tries = 0;
                while (($have = (int)(@filesize($path) ?: 0)) < $size) {
                    clearstatcache(true, $path);
                    try {
                        self::call($src, 'GET', "/transfer/v1/exports/$exportId/files/{$f['name']}", null, $path, $have . '-' . min($size - 1, $have + self::CHUNK - 1));
                    } catch (\Throwable $e) {
                        if (++$tries > 5) {
                            throw $e;
                        }
                        sleep(3 * $tries);
                    }
                    clearstatcache(true, $path);
                    self::progress($id, 'download', 5 + (int)(55 * ($done + (int)@filesize($path)) / $total));
                }
                if (!hash_equals((string)$f['sha256'], hash_file('sha256', $path))) {
                    throw new \RuntimeException(t('transfer.e_checksum', ['file' => $f['name']]));
                }
                $done += $size;
            }
            self::progress($id, 'download', 60, t('transfer.l_downloaded', ['size' => bytes($total)]));

            // 3. owner
            $owner = self::owner($o, $m['owner']);
            $result['owner'] = $owner['username'];
            if (!empty($owner['_created'])) {
                $created['user'] = (int)$owner['id'];
                $result['owner_created'] = true;
                self::progress($id, 'site', 62, t('transfer.l_user', ['user' => $owner['username']]));
            }

            // 4. site with its settings
            $php = self::nearestPhp((string)$m['settings']['php_version'], DomainService::phpVersions());
            $parentId = null;
            if ($m['type'] === 'sub') {
                $p = DB::one('SELECT * FROM domains WHERE name = ? AND user_id = ?', [$m['parent'], $owner['id']]);
                $parentId = $p['id'] ?? null;
            }
            $type = $m['type'] === 'sub' && $parentId ? 'sub' : ($m['type'] === 'sub' ? 'addon' : $m['type']);
            $d = DomainService::create(self::SYSTEM, (int)$owner['id'], $m['domain'], $php, $type, $parentId);
            $created['domain'] = (int)$d['id'];
            DB::q('UPDATE transfer_items SET new_domain_id = ? WHERE id = ?', [$d['id'], $id]);
            $s = $m['settings'];
            $fields = ['php_extensions' => $s['php_extensions'], 'opcache_enabled' => (int)$s['opcache_enabled'], 'opcache_memory' => (int)$s['opcache_memory'],
                'hsts' => (int)$s['hsts'], 'waf' => (int)$s['waf'], 'logs_enabled' => (int)$s['logs_enabled'], 'php_ini' => $s['php_ini'],
                'nginx_custom' => $s['nginx_custom'], 'nginx_custom_trusted' => 0];
            // admin-only php.ini lines can lift an account's limits: never taken over from another server unseen
            if (trim((string)($s['php_ini_admin'] ?? '')) !== '') {
                $result['warnings'][] = t('transfer.w_php_admin', ['lines' => mb_substr(trim((string)$s['php_ini_admin']), 0, 300)]);
            }
            try {
                DomainService::update(self::SYSTEM, DB::one('SELECT * FROM domains WHERE id = ?', [$d['id']]), $fields);
            } catch (\Throwable $e) {
                // e.g. custom Nginx rules this server rejects: keep the site, report it
                DomainService::update(self::SYSTEM, DB::one('SELECT * FROM domains WHERE id = ?', [$d['id']]), array_diff_key($fields, ['nginx_custom' => 1]));
                $result['warnings'][] = t('transfer.w_settings', ['e' => mb_substr($e->getMessage(), 0, 160)]);
            }
            self::progress($id, 'site', 65, t('transfer.l_site', ['php' => $php]));

            // 5. files
            $acct = 'hv' . $owner['id'];
            Ctl::call('transfer.import_files', ['id' => $exportId, 'account' => $acct, 'domain' => $m['domain']], 7200);
            self::progress($id, 'files', 75, t('transfer.l_files'));

            // 6. databases (renamed to this account's prefix: hv<old>_x -> hv<new>_x)
            $map = [];
            $oldHome = (string)$m['home'];
            $newHome = "/home/$acct/domains/{$m['domain']}";
            if ($oldHome !== $newHome && preg_match('#^/home/hv\d+/domains/[a-z0-9.-]+$#D', $oldHome)) {
                $map[$oldHome] = $newHome;
                $map[dirname($oldHome, 2)] = "/home/$acct";
            }
            $wpPass = '';
            $result['databases'] = [];
            foreach ($m['databases'] as $db) {
                $short = preg_replace('/^hv\d+_/', '', (string)$db['name']);
                if (!preg_match('/^[a-z0-9_]{1,16}$/D', $short)) {
                    throw new \RuntimeException('Invalid database name in export');
                }
                if (DB::one('SELECT id FROM db_instances WHERE name = ?', ["hv{$owner['id']}_$short"])) {
                    $short = DatabaseService::freeName((int)$owner['id'], substr($short, 0, 13));
                }
                $new = DatabaseService::create(self::SYSTEM, (int)$owner['id'], $short);
                $created['dbs'][] = $new['name'];
                $r = Ctl::call('transfer.import_db', ['id' => $exportId, 'account' => $acct, 'name' => $new['name'], 'file' => "db-{$db['name']}.sql.gz",
                    'password' => $new['password'], 'hash' => $db['hash'] ?? ''], 7200);
                if ($new['name'] !== $db['name']) {
                    $map[$db['name']] = $new['name'];
                }
                if (empty($r['password_kept'])) {
                    $wpPass = $new['password'];
                    $result['warnings'][] = t('transfer.w_db_password', ['db' => $new['name']]);
                }
                $result['databases'][] = ['old' => $db['name'], 'new' => $new['name'], 'password_kept' => !empty($r['password_kept'])];
                self::progress($id, 'databases', 85, t('transfer.l_db', ['old' => $db['name'], 'new' => $new['name']]));
            }

            // 7. configuration files and WordPress paths
            if ($map || $wpPass !== '') {
                $p = Ctl::call('transfer.patch', ['account' => $acct, 'domain' => $m['domain'], 'map' => $map, 'wp_password' => $wpPass]);
                if ($p['changed']) {
                    self::progress($id, 'config', 88, t('transfer.l_patched', ['files' => implode(', ', $p['changed'])]));
                }
            }
            if ($oldHome !== $newHome && $m['databases']) {
                $w = Ctl::call('transfer.wp_replace', ['account' => $acct, 'domain' => $m['domain'], 'old' => $oldHome, 'new' => $newHome, 'php' => $php], 1900);
                if (!empty($w['wordpress'])) {
                    empty($w['ok']) ? $result['warnings'][] = t('transfer.w_wp', ['e' => $w['note'] ?? '']) : self::progress($id, 'config', 90, t('transfer.l_wp'));
                }
            }

            // 8. aliases, redirects, cron
            foreach ($m['aliases'] as $a) {
                try {
                    DomainService::create(self::SYSTEM, (int)$owner['id'], $a, $php, 'alias', (int)$d['id']);
                } catch (\Throwable $e) {
                    $result['warnings'][] = t('transfer.w_alias', ['name' => $a]);
                }
            }
            if ($m['redirects']) {
                foreach ($m['redirects'] as $r) {
                    DB::insert('redirects', ['domain_id' => $d['id'], 'source' => (string)$r['source'], 'target' => (string)$r['target'], 'code' => (int)$r['code']]);
                }
                try {
                    DomainService::reapply(DB::one('SELECT * FROM domains WHERE id = ?', [$d['id']]));
                } catch (\Throwable $e) {
                    DB::q('DELETE FROM redirects WHERE domain_id = ?', [$d['id']]);
                    $result['warnings'][] = t('transfer.w_redirects');
                }
            }
            foreach ($m['cron'] as $c) {
                DB::insert('cron_jobs', ['user_id' => $owner['id'], 'domain_id' => $d['id'], 'label' => mb_substr((string)$c['label'], 0, 80),
                    'schedule' => (string)$c['schedule'], 'command' => strtr((string)$c['command'], $map), 'enabled' => (int)$c['enabled'], 'log_output' => (int)$c['log_output']]);
            }
            if ($m['cron']) {
                try {
                    CronService::sync((int)$owner['id']);
                } catch (\Throwable $e) {
                    $result['warnings'][] = t('transfer.w_cron', ['e' => mb_substr($e->getMessage(), 0, 120)]);
                }
            }

            // 9. certificate from the old server, replaced by Let's Encrypt once DNS points here
            if (is_file("$stage/ssl.json")) {
                $ssl = json_decode((string)file_get_contents("$stage/ssl.json"), true) ?: [];
                try {
                    Ctl::call('ssl.install', ['domain' => $m['domain'], 'cert' => (string)($ssl['cert'] ?? ''), 'key' => (string)($ssl['key'] ?? '')]);
                    DomainService::update(self::SYSTEM, DB::one('SELECT * FROM domains WHERE id = ?', [$d['id']]), ['ssl_mode' => 'custom', 'force_https' => (int)$s['force_https']]);
                    DB::q("UPDATE domains SET ssl_transferred = 1, ssl_auto = 'pending', ssl_auto_attempts = 0, ssl_auto_note = 'transfer' WHERE id = ?", [$d['id']]);
                    self::progress($id, 'ssl', 93, t('transfer.l_ssl'));
                } catch (\Throwable $e) {
                    $result['warnings'][] = t('transfer.w_ssl', ['e' => mb_substr($e->getMessage(), 0, 120)]);
                }
            }

            // 10. does it answer here?
            $result['check'] = Ctl::call('transfer.check', ['domain' => $m['domain']], 60);
            $result['ip'] = self::serverIp();
            self::progress($id, 'done', 100, t('transfer.l_done'));
            DB::q("UPDATE transfer_items SET status = 'done', result = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?", [json_encode($result), $id]);
            Audit::log('transfer.import', $m['domain'], ['owner' => $owner['username']], self::SYSTEM);
        } catch (\Throwable $e) {
            self::rollback($created);
            self::progress($id, 'failed', (int)DB::q('SELECT progress FROM transfer_items WHERE id = ?', [$id])->fetchColumn(), t('transfer.l_failed', ['e' => mb_substr($e->getMessage(), 0, 400)]));
            DB::q("UPDATE transfer_items SET status = 'failed', result = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?", [json_encode($result + ['error' => mb_substr($e->getMessage(), 0, 500)]), $id]);
        } finally {
            if ($exportId) {
                try {
                    self::call($src, 'POST', "/transfer/v1/exports/$exportId/delete");
                } catch (\Throwable) {
                }
                try {
                    Ctl::call('transfer.stage', ['id' => $exportId, 'remove' => true]);
                } catch (\Throwable) {
                }
            }
        }
    }

    /** Removes what a failed import created here (only that), so the site can simply be tried again. */
    private static function rollback(array $c): void
    {
        foreach ($c['dbs'] as $n) {
            try {
                Ctl::call('db.drop', ['name' => $n]);
            } catch (\Throwable) {
            }
            DB::q('DELETE FROM db_instances WHERE name = ?', [$n]);
        }
        if ($c['domain'] && ($d = DB::one('SELECT * FROM domains WHERE id = ?', [$c['domain']]))) {
            try {
                DomainService::delete(self::SYSTEM, $d, true);
            } catch (\Throwable) {
                DB::q('DELETE FROM domains WHERE id = ?', [$c['domain']]);
            }
        }
        if ($c['user'] && !DB::one('SELECT id FROM domains WHERE user_id = ?', [$c['user']])) {
            DB::q('DELETE FROM users WHERE id = ?', [$c['user']]);
        }
    }

    /** The receiving panel user: an existing one chosen on the selection page, or the same user name (created if missing). */
    private static function owner(array $o, array $src): array
    {
        if (!empty($o['owner_id'])) {
            $u = DB::one('SELECT * FROM users WHERE id = ?', [(int)$o['owner_id']]);
            if (!$u) {
                throw new \RuntimeException('Selected user no longer exists');
            }
            return $u;
        }
        $name = strtolower((string)$src['username']);
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/D', $name)) {
            throw new \RuntimeException('Invalid user name in export');
        }
        $u = DB::one('SELECT * FROM users WHERE username = ?', [$name]);
        if ($u) {
            return $u;
        }
        $hash = (string)($src['password'] ?? '');
        $admin = DB::one("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $id = DB::insert('users', ['parent_id' => $admin['id'] ?? null, 'role' => 'user', 'username' => $name,
            'email' => filter_var($src['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: $name . '@localhost.localdomain',
            // the same password as on the old server (Argon2id/bcrypt hash); anything else: a random one
            'password' => preg_match('/^\$(argon2id|argon2i|2y)\$/', $hash) ? $hash : \Havoc\Core\Auth::hash(bin2hex(random_bytes(16)))]);
        Audit::log('user.create', $name, ['via' => 'transfer'], self::SYSTEM);
        return DB::one('SELECT * FROM users WHERE id = ?', [$id]) + ['_created' => true];
    }

    public static function serverIp(): string
    {
        foreach (SystemStats::ips() as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
        return SystemStats::ips()[0] ?? '';
    }
}
