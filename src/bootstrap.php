<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $map = [
        'Havoc\\Ctl\\' => HAVOC_ROOT . '/ctl/lib/',
        'Havoc\\'      => HAVOC_ROOT . '/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require __DIR__ . '/helpers.php';
