<?php
declare(strict_types=1);

namespace Havoc\Core;

final class Response
{
    public function __construct(public string $body = '', public int $status = 200, public array $headers = [])
    {
    }

    public static function redirect(string $to, int $status = 302): self
    {
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';   // never redirect off-site
        }
        return new self('', $status, ['Location' => $to]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function send(): void
    {
        if (headers_sent()) {
            return;                                           // body was streamed directly (file downloads)
        }
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}
