<?php

declare(strict_types=1);

use ExpressPHP\Core\Application;
use ExpressPHP\Http\Request;

$root = dirname(__DIR__);

require $root . '/src/bootstrap.php';

$config = require $root . '/config/app.php';
$app = new Application($config);

(require $root . '/routes/api.php')($app);

$app->run(Request::capture($config['trusted_proxies'] ?? []));
