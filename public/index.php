<?php
declare(strict_types=1);

define('HAVOC_ROOT', dirname(__DIR__));
require HAVOC_ROOT . '/src/bootstrap.php';
Havoc\Core\App::run();
