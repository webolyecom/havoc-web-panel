<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Auth, Response, Security};
use Havoc\Services\OAuthService;

/** OAuth 2.1 endpoints for the Claude connector (metadata, client registration, consent, tokens). */
final class OAuthController extends Controller
{
    private static function json(array $data, int $status = 200, array $headers = []): Response
    {
        $r = Response::json($data, $status);
        $r->headers += ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Access-Control-Allow-Origin' => '*'] + $headers;
        return $r;
    }

    private static function off(): ?Response
    {
        return OAuthService::enabled() ? null : self::json(['error' => 'connector_disabled'], 404);
    }

    /** RFC 9728: where the MCP resource's authorization server is. */
    public function protectedResource(): Response
    {
        return self::off() ?? self::json(['resource' => OAuthService::resource(), 'authorization_servers' => [OAuthService::base()],
            'scopes_supported' => OAuthService::SCOPES, 'bearer_methods_supported' => ['header'], 'resource_name' => 'Havoc WEB Panel']);
    }

    /** RFC 8414 authorization server metadata. */
    public function metadata(): Response
    {
        $b = OAuthService::base();
        return self::off() ?? self::json(['issuer' => $b, 'authorization_endpoint' => "$b/oauth/authorize", 'token_endpoint' => "$b/oauth/token",
            'registration_endpoint' => "$b/oauth/register", 'response_types_supported' => ['code'], 'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'], 'scopes_supported' => OAuthService::SCOPES]);
    }

    public function register(): Response
    {
        if ($r = self::off()) {
            return $r;
        }
        try {
            return self::json(OAuthService::register($this->req->json()), 201);
        } catch (\InvalidArgumentException $e) {
            return self::json(['error' => 'invalid_client_metadata', 'error_description' => $e->getMessage()], 400);
        }
    }

    private function page(string $msg, int $status = 400): Response
    {
        $r = view('oauth/error', ['message' => $msg], 'auth/layout');
        $r->status = $status;
        return $r;
    }

    private static function back(string $redirect, array $params): Response
    {
        return new Response('', 302, ['Location' => $redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($params)]);
    }

    /** GET /oauth/authorize: checks the request, sends the user to log in if needed, shows the consent page. */
    public function authorize(): Response
    {
        if (!OAuthService::enabled()) {
            return $this->page(t('oauth.disabled'), 404);
        }
        $q = $_GET;
        $client = OAuthService::client((string)($q['client_id'] ?? ''));
        $redirect = (string)($q['redirect_uri'] ?? '');
        if (!$client || !OAuthService::redirectMatches($client['redirect_uris'], $redirect)) {
            return $this->page(t('oauth.bad_client'));                   // never redirect to an unverified address
        }
        $state = (string)($q['state'] ?? '');
        if (($q['response_type'] ?? '') !== 'code') {
            return self::back($redirect, ['error' => 'unsupported_response_type', 'state' => $state]);
        }
        $challenge = (string)($q['code_challenge'] ?? '');
        if (($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $challenge)) {
            return self::back($redirect, ['error' => 'invalid_request', 'error_description' => 'PKCE S256 required', 'state' => $state]);
        }
        $u = Auth::user();
        if (!$u) {
            $_SESSION['oauth_next'] = '/oauth/authorize?' . http_build_query(array_diff_key($q, ['hop' => 1]));
            return $this->to('/login');
        }
        $rid = bin2hex(random_bytes(12));
        $_SESSION['oauth_req'] = array_slice(($_SESSION['oauth_req'] ?? []) + [$rid => [
            'client_id' => $client['client_id'], 'client_name' => $client['name'], 'redirect' => $redirect, 'challenge' => $challenge, 'state' => $state,
            'scopes' => OAuthService::scopes($q['scope'] ?? null), 'resource' => (string)($q['resource'] ?? OAuthService::resource()), 'at' => time()]], -5, null, true);
        $p = parse_url($redirect);
        Security::csp($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
        return view('oauth/consent', ['rid' => $rid, 'client' => $client, 'redirectHost' => $p['host'] ?? '', 'user' => $u,
            'scopes' => $_SESSION['oauth_req'][$rid]['scopes']], 'auth/layout');
    }

    /** POST /oauth/authorize: the user's decision. */
    public function decide(): Response
    {
        $u = Auth::user();
        $rid = $this->req->str('rid');
        $r = $_SESSION['oauth_req'][$rid] ?? null;
        unset($_SESSION['oauth_req'][$rid]);
        if (!$u || !$r || time() - $r['at'] > 900) {
            return $this->page(t('oauth.expired'));
        }
        if ($this->req->str('decision') !== 'allow') {
            return self::back($r['redirect'], ['error' => 'access_denied', 'state' => $r['state']]);
        }
        $scopes = in_array('write', $r['scopes'], true) && $this->req->post('write') === '1' ? ['read', 'write'] : ['read'];
        $code = OAuthService::issueCode($r['client_id'], (int)$u['id'], $r['redirect'], $r['challenge'], $scopes, $r['resource']);
        Audit::log('claude.connect', $r['client_name'], ['scopes' => implode(' ', $scopes)]);
        return self::back($r['redirect'], ['code' => $code, 'state' => $r['state'], 'iss' => OAuthService::base()]);
    }

    public function token(): Response
    {
        if ($r = self::off()) {
            return $r;
        }
        $in = $_POST ?: $this->req->json();
        $client = (string)($in['client_id'] ?? '');
        try {
            return match ((string)($in['grant_type'] ?? '')) {
                'authorization_code' => self::json(OAuthService::exchangeCode((string)($in['code'] ?? ''), $client, (string)($in['redirect_uri'] ?? ''), (string)($in['code_verifier'] ?? ''))),
                'refresh_token' => self::json(OAuthService::refresh((string)($in['refresh_token'] ?? ''), $client)),
                default => self::json(['error' => 'unsupported_grant_type'], 400),
            };
        } catch (\DomainException $e) {
            return self::json(['error' => $e->getMessage()], 400);
        }
    }
}
