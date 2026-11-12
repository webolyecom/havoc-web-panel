<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public array $params = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $this->path = '/' . trim(preg_replace('#/+#', '/', $path), '/');
    }

    public function post(string $k, mixed $d = null): mixed
    {
        return $_POST[$k] ?? $d;
    }

    public function str(string $k, string $d = ''): string
    {
        $v = $_POST[$k] ?? $_GET[$k] ?? $d;
        return is_string($v) ? trim($v) : $d;
    }

    public function json(): array
    {
        static $j = null;
        if ($j === null) {
            $j = json_decode((string)file_get_contents('php://input'), true);
            $j = is_array($j) ? $j : [];
        }
        return $j;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function header(string $name): ?string
    {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$k] ?? null;
    }

    public function bearer(): ?string
    {
        $h = $this->header('Authorization');
        return ($h && preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) ? $m[1] : null;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function wantsJson(): bool
    {
        return $this->isApi() || str_contains($this->header('Accept') ?? '', 'application/json');
    }

    public function host(): string
    {
        return strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    }

    public function isHttps(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}
