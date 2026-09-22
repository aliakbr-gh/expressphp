<?php

declare(strict_types=1);

$root = dirname(__DIR__);

defined('APP_STARTED_AT') || define('APP_STARTED_AT', microtime(true));

spl_autoload_register(static function (string $class) use ($root): void {
    $namespaces = [
        'App\\' => $root . '/app/',
        'ExpressPHP\\' => $root . '/src/',
    ];

    foreach ($namespaces as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $directory . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require $file;
        }
        return;
    }
});

ExpressPHP\Core\Environment::load($root . '/.env');

require_once $root . '/src/helpers.php';
