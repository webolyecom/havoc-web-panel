<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, array $handler, array $mw = []): void
    {
        $re = preg_replace_callback('/\{(\w+)\}/', static fn($m) =>
            '(?P<' . $m[1] . '>' . (str_ends_with($m[1], 'id') ? '[0-9]+' : '[A-Za-z0-9_.-]+') . ')', $path);
        $this->routes[] = [$method, '#^' . $re . '$#', $handler, $mw];
    }

    public function get(string $p, array $h, array $mw = []): void { $this->add('GET', $p, $h, $mw); }
    public function post(string $p, array $h, array $mw = []): void { $this->add('POST', $p, $h, $mw); }
    public function delete(string $p, array $h, array $mw = []): void { $this->add('DELETE', $p, $h, $mw); }

    public function dispatch(Request $req): Response
    {
        foreach ($this->routes as [$method, $re, $handler, $mw]) {
            if ($method !== $req->method || !preg_match($re, $req->path, $m)) {
                continue;
            }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($req->method !== 'GET' && !$req->isApi()) {
                Csrf::verify($req);
            }
            foreach ($mw as $name) {
                if ($res = Middleware::run($name, $req)) {
                    return $res;
                }
            }
            return (new $handler[0]($req))->{$handler[1]}();
        }
        throw new HttpException(404);
    }
}
