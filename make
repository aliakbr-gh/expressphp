<?php

declare(strict_types=1);

use ExpressPHP\Core\MakeCommand;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/config/app.php';

try {
    exit((new MakeCommand(__DIR__, (string) ($config['timezone'] ?? 'UTC')))->run(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Make failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

