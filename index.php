<?php

declare(strict_types=1);

use ExpressPHP\Core\Application;
use ExpressPHP\Http\Request;

require __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/config/app.php';
$app = new Application($config);

(require __DIR__ . '/routes/api.php')($app);

$app->run(Request::capture($config['trusted_proxies'] ?? []));
