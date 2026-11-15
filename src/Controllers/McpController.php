<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Auth, Response};
use Havoc\Services\{McpTools, OAuthService, UpdateService};

/**
 * MCP endpoint (Streamable HTTP transport, JSON responses, no server-sent events). Authenticated with the OAuth access
 * tokens of OAuthService; a missing or expired token gets 401 with the resource metadata address, which is how Claude
 * discovers where to log in.
 */
final class McpController extends Controller
{
    private const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private static function unauthorized(): Response
    {
        return new Response(json_encode(['error' => 'invalid_token']), 401, ['Content-Type' => 'application/json',
            'WWW-Authenticate' => 'Bearer resource_metadata="' . OAuthService::base() . '/.well-known/oauth-protected-resource/mcp"']);
    }

    public function handle(): Response
    {
        if (!OAuthService::enabled()) {
            return Response::json(['error' => 'connector_disabled'], 404);
        }
        // DNS rebinding protection: browsers send Origin, Claude's servers do not
        $origin = $this->req->header('Origin');
        if ($origin !== null && $origin !== OAuthService::base()) {
            return Response::json(['error' => 'origin_not_allowed'], 403);
        }
        $auth = OAuthService::authenticate($this->req->bearer(), $this->req->ip());
        if (!$auth) {
            return self::unauthorized();
        }
        [$user, $scopes] = $auth;
        Auth::setUser($user);
        if ($this->req->method !== 'POST') {
            return new Response('', 405, ['Allow' => 'POST']);
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, 16 * 1024 * 1024);
        $msg = json_decode($raw, true);
        if (!is_array($msg)) {
            return Response::json(self::error(null, -32700, 'Parse error'), 400);
        }
        $batch = array_is_list($msg);
        $out = [];
        foreach ($batch ? $msg : [$msg] as $m) {
            if (!is_array($m) || ($m['jsonrpc'] ?? '') !== '2.0' || !is_string($m['method'] ?? null)) {
                if (isset($m['result']) || isset($m['error'])) {
                    continue;                                            // responses from the client: nothing to answer
                }
                $out[] = self::error($m['id'] ?? null, -32600, 'Invalid request');
                continue;
            }
            if (!array_key_exists('id', $m)) {
                continue;                                                // notification (e.g. notifications/initialized)
            }
            $out[] = $this->dispatch($m, $user, $scopes);
        }
        if (!$out) {
            return new Response('', 202);
        }
        return new Response(json_encode($batch ? $out : $out[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 200,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    private static function error(mixed $id, int $code, string $msg): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]];
    }

    private static function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function dispatch(array $m, array $user, array $scopes): array
    {
        $id = $m['id'];
        $p = is_array($m['params'] ?? null) ? $m['params'] : [];
        switch ($m['method']) {
            case 'initialize':
                $v = in_array($p['protocolVersion'] ?? '', self::VERSIONS, true) ? $p['protocolVersion'] : self::VERSIONS[0];
                return self::ok($id, ['protocolVersion' => $v, 'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'havoc-web-panel', 'title' => 'Havoc WEB Panel', 'version' => UpdateService::current()],
                    'instructions' => 'Manages websites on a Havoc WEB Panel server as the account "' . $user['username'] . '". '
                        . 'Typical flow: add_domain -> (DNS points here) issue_ssl -> install_wordpress, or write_file to publish pages. '
                        . 'Use list_domains first to see what exists. Deleting is not possible through this connector. '
                        . 'Passwords returned by install_wordpress and create_database are shown only once: always give them to the user.']);
            case 'ping':
                return self::ok($id, new \stdClass());
            case 'tools/list':
                $defs = McpTools::definitions();
                if (!in_array('write', $scopes, true)) {
                    $defs = array_values(array_filter($defs, static fn($d) => $d['annotations']['readOnlyHint']));
                }
                return self::ok($id, ['tools' => $defs]);
            case 'tools/call':
                $name = (string)($p['name'] ?? '');
                $args = is_array($p['arguments'] ?? null) ? $p['arguments'] : [];
                try {
                    $res = (new McpTools($user, $scopes))->call($name, $args);
                    return self::ok($id, ['content' => [['type' => 'text', 'text' => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]],
                        'structuredContent' => $res, 'isError' => false]);
                } catch (\Throwable $e) {
                    if (!$e instanceof \DomainException && !$e instanceof \InvalidArgumentException && !$e instanceof \RuntimeException) {
                        error_log('[havoc mcp] ' . $e);
                    }
                    return self::ok($id, ['content' => [['type' => 'text', 'text' => $e->getMessage() ?: 'The operation failed.']], 'isError' => true]);
                }
            case 'resources/list':
                return self::ok($id, ['resources' => []]);
            case 'prompts/list':
                return self::ok($id, ['prompts' => []]);
            default:
                return self::error($id, -32601, 'Method not found: ' . $m['method']);
        }
    }
}
