<?php
declare(strict_types=1);

namespace Havoc\Core;

final class View
{
    public static function render(string $tpl, array $data = [], string $layout = 'layout'): string
    {
        $content = self::capture($tpl, $data);
        if ($layout === '') {
            return $content;
        }
        return self::capture($layout, $data + ['content' => $content]);
    }

    private static function capture(string $tpl, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/]+$#i', $tpl)) {
            throw new \InvalidArgumentException('Bad template name');
        }
        extract($data, EXTR_SKIP);
        $content ??= '';
        ob_start();
        require HAVOC_ROOT . '/views/' . $tpl . '.php';
        return (string)ob_get_clean();
    }
}
