<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{DB, Settings};

/**
 * OAuth 2.1 authorization server for the Claude connector (MCP): dynamic client registration (RFC 7591), authorization
 * code with PKCE S256 only, rotating refresh tokens. Codes and tokens are random and stored as SHA-256 hashes only.
 * A token acts as the panel user who approved it, with the same isolation as that user in the web interface.
 */
final class OAuthService
{
    public const SCOPES = ['read', 'write'];
    private const ACCESS_TTL  = 3600;
    private const REFRESH_TTL = 30 * 86400;
    private const CODE_TTL    = 600;
    private const MAX_CLIENTS = 500;

    public static function enabled(): bool
    {
        return Settings::get('mcp.enabled', '1') === '1';
    }

    /**
     * Public base URL of the panel: host from the Host header, port from the port Nginx actually accepted the
     * connection on (SERVER_PORT). The Host header alone is not enough: with HTTP/2 it can arrive without the port,
     * and the metadata would then send Claude to port 443, where the sites' web server answers instead of the panel.
     */
    public static function base(): string
    {
        $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if (preg_match('/^\[([0-9a-f:]+)\](?::\d{1,5})?$/D', $h, $m)) {
            $host = '[' . $m[1] . ']';
        } elseif (preg_match('/^([a-z0-9.-]{1,253})(?::\d{1,5})?$/D', $h, $m)) {
            $host = $m[1];
        } else {
            $host = 'localhost';
        }
        $port = (int)($_SERVER['SERVER_PORT'] ?? 443);
        return 'https://' . $host . ($port > 0 && $port !== 443 ? ':' . $port : '');
    }

    public static function resource(): string
    {
        return self::base() . '/mcp';
    }

    /** Redirect URIs Claude uses: the hosted apps' callback, and loopback (any port) for Claude Code / desktop tools. */
    public static function redirectAllowed(string $uri): bool
    {
        if (in_array($uri, ['https://claude.ai/api/mcp/auth_callback', 'https://claude.com/api/mcp/auth_callback'], true)) {
            return true;
        }
        return (bool)preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?(/[A-Za-z0-9._~/-]*)?$#D', $uri);
    }

    /** Exact match, except that the port of a loopback redirect may differ (RFC 8252 section 7.3). */
    public static function redirectMatches(array $registered, string $uri): bool
    {
        foreach ($registered as $r) {
            if ($r === $uri) {
                return true;
            }
            $a = parse_url($r);
            $b = parse_url($uri);
            if (($a['scheme'] ?? '') === 'http' && in_array($a['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
                && ($a['host'] ?? '') === ($b['host'] ?? '') && ($b['scheme'] ?? '') === 'http' && ($a['path'] ?? '') === ($b['path'] ?? '')
                && !isset($b['query'], $b['fragment'], $b['user'])) {
                return true;
            }
        }
        return false;
    }

    /** @return array client registration response */
    public static function register(array $in): array
    {
        $uris = array_values(array_unique(array_filter((array)($in['redirect_uris'] ?? []), 'is_string')));
        if (!$uris || count($uris) > 5) {
            throw new \InvalidArgumentException('redirect_uris required');
        }
        foreach ($uris as $u) {
            if (!self::redirectAllowed($u)) {
                throw new \InvalidArgumentException('redirect_uri not allowed: ' . mb_substr($u, 0, 100));
            }
        }
        $method = (string)($in['token_endpoint_auth_method'] ?? 'none');
        if ($method !== 'none') {
            throw new \InvalidArgumentException('Only public clients (token_endpoint_auth_method "none") are supported');
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($in['client_name'] ?? 'Claude'))), 0, 80) ?: 'Claude';
        // keep the table small: drop the oldest registrations nobody has used
        $n = (int)DB::q('SELECT COUNT(*) FROM oauth_clients')->fetchColumn();
        if ($n >= self::MAX_CLIENTS) {
            DB::q('DELETE FROM oauth_clients WHERE client_id IN (SELECT client_id FROM oauth_clients c WHERE NOT EXISTS
                (SELECT 1 FROM oauth_tokens t WHERE t.client_id = c.client_id) ORDER BY created_at LIMIT ?)', [$n - self::MAX_CLIENTS + 1]);
        }
        $id = 'hvc-' . bin2hex(random_bytes(16));
        DB::q('INSERT INTO oauth_clients (client_id, name, redirect_uris) VALUES (?, ?, ?)', [$id, $name, json_encode($uris, JSON_UNESCAPED_SLASHES)]);
        return ['client_id' => $id, 'client_name' => $name, 'redirect_uris' => $uris, 'client_id_issued_at' => time(),
            'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none'];
    }

    public static function client(string $id): ?array
    {
        $c = DB::one('SELECT * FROM oauth_clients WHERE client_id = ?', [$id]);
        if ($c) {
            $c['redirect_uris'] = json_decode($c['redirect_uris'], true) ?: [];
        }
        return $c;
    }

    /** @return string[] granted scopes from a space separated request (default: read + write) */
    public static function scopes(?string $req): array
    {
        $s = array_values(array_intersect(self::SCOPES, preg_split('/\s+/', trim((string)$req)) ?: []));
        return $s ?: self::SCOPES;
    }

    public static function issueCode(string $clientId, int $userId, string $redirect, string $challenge, array $scopes, string $resource): string
    {
        DB::q('DELETE FROM oauth_codes WHERE expires < ?', [time()]);
        $code = bin2hex(random_bytes(32));
        DB::q('INSERT INTO oauth_codes (code_hash, client_id, user_id, redirect_uri, challenge, scope, resource, expires) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [hash('sha256', $code), $clientId, $userId, $redirect, $challenge, implode(' ', $scopes), $resource, time() + self::CODE_TTL]);
        return $code;
    }

    /** @throws \DomainException with an OAuth error code */
    public static function exchangeCode(string $code, string $clientId, string $redirect, string $verifier): array
    {
        $row = DB::one('SELECT * FROM oauth_codes WHERE code_hash = ?', [hash('sha256', $code)]);
        if ($row) {
            DB::q('DELETE FROM oauth_codes WHERE code_hash = ?', [$row['code_hash']]);   // single use, whatever happens next
        }
        if (!$row || $row['expires'] < time() || !hash_equals($row['client_id'], $clientId) || !hash_equals($row['redirect_uri'], $redirect)) {
            throw new \DomainException('invalid_grant');
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)
            || !hash_equals($row['challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
            throw new \DomainException('invalid_grant');
        }
        $user = DB::one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$row['user_id']]);
        if (!$user) {
            throw new \DomainException('invalid_grant');
        }
        $client = self::client($clientId);
        return self::issueTokens((int)$user['id'], $clientId, (string)($client['name'] ?? 'Claude'), $row['scope']);
    }

    public static function refresh(string $refresh, string $clientId): array
    {
        $t = DB::one('SELECT * FROM oauth_tokens WHERE refresh_hash = ?', [hash('sha256', $refresh)]);
        if (!$t || $t['refresh_expires'] < time() || !hash_equals($t['client_id'], $clientId)) {
            throw new \DomainException('invalid_grant');
        }
        if (!DB::one("SELECT id FROM users WHERE id = ? AND status = 'active'", [$t['user_id']])) {
            throw new \DomainException('invalid_grant');
        }
        DB::q('DELETE FROM oauth_tokens WHERE id = ?', [$t['id']]);                     // rotation: the old pair is gone
        return self::issueTokens((int)$t['user_id'], $t['client_id'], $t['client_name'], $t['scope']);
    }

    private static function issueTokens(int $userId, string $clientId, string $clientName, string $scope): array
    {
        $access = 'hvm_' . bin2hex(random_bytes(32));
        $refresh = 'hvr_' . bin2hex(random_bytes(32));
        DB::q('INSERT INTO oauth_tokens (user_id, client_id, client_name, scope, access_hash, access_expires, refresh_hash, refresh_expires) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $clientId, $clientName, $scope, hash('sha256', $access), time() + self::ACCESS_TTL, hash('sha256', $refresh), time() + self::REFRESH_TTL]);
        DB::q('DELETE FROM oauth_tokens WHERE refresh_expires < ?', [time()]);
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => $scope];
    }

    /** @return array{0:array,1:string[]}|null [user, scopes] for a valid access token */
    public static function authenticate(?string $token, string $ip): ?array
    {
        if (!$token || !preg_match('/^hvm_[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $t = DB::one('SELECT * FROM oauth_tokens WHERE access_hash = ?', [hash('sha256', $token)]);
        if (!$t || $t['access_expires'] < time()) {
            return null;
        }
        $u = DB::one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$t['user_id']]);
        if (!$u) {
            return null;
        }
        DB::q('UPDATE oauth_tokens SET last_used = CURRENT_TIMESTAMP, last_ip = ? WHERE id = ?', [$ip, $t['id']]);
        return [$u, explode(' ', $t['scope'])];
    }

    /** Connections of a user, one row per client (newest token). */
    public static function connections(int $userId): array
    {
        return DB::all('SELECT client_id, client_name, scope, MIN(created_at) AS since, MAX(COALESCE(last_used, created_at)) AS last_used, MAX(last_ip) AS last_ip
            FROM oauth_tokens WHERE user_id = ? GROUP BY client_id, client_name, scope ORDER BY last_used DESC', [$userId]);
    }

    public static function revoke(int $userId, string $clientId): void
    {
        DB::q('DELETE FROM oauth_tokens WHERE user_id = ? AND client_id = ?', [$userId, $clientId]);
        DB::q('DELETE FROM oauth_codes WHERE user_id = ? AND client_id = ?', [$userId, $clientId]);
    }

    public static function revokeAll(): void
    {
        DB::q('DELETE FROM oauth_tokens');
        DB::q('DELETE FROM oauth_codes');
    }
}
