<?php

declare(strict_types=1);

use ExpressPHP\Core\Application;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

$root = dirname(__DIR__);

require $root . '/src/bootstrap.php';

try {
    $config = require $root . '/config/app.php';
    $app = new Application($config);

    (require $root . '/routes/api.php')($app);
} catch (Throwable) {
    (new Response())->error('Internal Server Error', 500)->emit();
    exit;
}

$app->run(Request::capture($config['trusted_proxies'] ?? []));
