<?php
declare(strict_types=1);

use Havoc\Core\{Csrf, I18n, Response, Security, View};

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function t(string $key, array $replace = []): string
{
    return I18n::t($key, $replace);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function nonce(): string
{
    return Security::nonce();
}

function asset(string $file): string
{
    $path = HAVOC_ROOT . '/public/assets/' . $file;
    return '/assets/' . $file . '?v=' . (is_file($path) ? filemtime($path) : 0);
}

function view(string $tpl, array $data = [], string $layout = 'layout'): Response
{
    return new Response(View::render($tpl, $data, $layout));
}

function bytes(int|float $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) {
        $b /= 1024;
        $i++;
    }
    return round($b, $i ? 1 : 0) . ' ' . $u[$i];
}
