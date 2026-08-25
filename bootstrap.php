<?php

declare(strict_types=1);

defined('APP_STARTED_AT') || define('APP_STARTED_AT', microtime(true));

spl_autoload_register(static function (string $class): void {
    $namespaces = [
        'App\\' => __DIR__ . '/app/',
        'ExpressPHP\\' => __DIR__ . '/src/',
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

ExpressPHP\Core\Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/src/helpers.php';
