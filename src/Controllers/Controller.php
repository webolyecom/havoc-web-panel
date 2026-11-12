<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Auth, HttpException, Request, Response, Session};

abstract class Controller
{
    public function __construct(protected Request $req)
    {
    }

    protected function user(): array
    {
        return Auth::user() ?? throw new HttpException(401);
    }

    protected function param(string $k): string
    {
        return $this->req->params[$k] ?? '';
    }

    protected function flash(string $type, string $msg): void
    {
        Session::flash($type, $msg);
    }

    protected function to(string $path): Response
    {
        return Response::redirect($path);
    }

    protected function abort(int $code): never
    {
        throw new HttpException($code);
    }
}
