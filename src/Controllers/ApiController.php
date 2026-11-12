<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Response, Scope};
use Havoc\Services\{DomainService, SystemStats};

/** RESTful JSON API (Bearer token). Same Scope isolation as the web UI. Spec: /api/v1/openapi.json */
final class ApiController extends Controller
{
    public function openapi(): Response
    {
        $f = HAVOC_ROOT . '/docs/openapi.json';
        return new Response((string)file_get_contents($f), 200, ['Content-Type' => 'application/json']);
    }

    public function me(): Response
    {
        $u = $this->user();
        return Response::json(['id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role'], 'email' => $u['email']]);
    }

    public function domains(): Response
    {
        return Response::json(['data' => array_map($this->present(...), Scope::domains($this->user()))]);
    }

    public function domain(): Response
    {
        $d = Scope::domain($this->user(), (int)$this->param('id')) ?? $this->abort(404);
        return Response::json(['data' => $this->present($d)]);
    }

    public function domainCreate(): Response
    {
        $u = $this->user();
        $b = $this->req->json();
        $owner = $u['role'] === 'user' ? (int)$u['id'] : (int)($b['user_id'] ?? $u['id']);
        try {
            $d = DomainService::create($u, $owner, (string)($b['name'] ?? ''), (string)($b['php_version'] ?? '8.2'));
            return Response::json(['data' => $this->present($d)], 201);
        } catch (\InvalidArgumentException | \DomainException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function domainDelete(): Response
    {
        $u = $this->user();
        $d = Scope::domain($u, (int)$this->param('id')) ?? $this->abort(404);
        DomainService::delete($u, $d, !empty($this->req->json()['purge']));
        return new Response('', 204);
    }

    public function stats(): Response
    {
        return Response::json(['data' => SystemStats::snapshot()]);
    }

    private function present(array $d): array
    {
        return ['id' => (int)$d['id'], 'name' => $d['name'], 'owner' => $d['username'] ?? null, 'php_version' => $d['php_version'],
            'ssl_mode' => $d['ssl_mode'], 'force_https' => (bool)$d['force_https'], 'waf' => (bool)$d['waf'], 'status' => $d['status']];
    }
}
