<?php

declare(strict_types=1);

use ExpressPHP\Database\Database;
use ExpressPHP\Auth\Password;
use Seeders\DatabaseSeeder;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/seeders/DatabaseSeeder.php';

$config = require __DIR__ . '/config/app.php';
Database::configure($config['databases'] ?? []);
Password::configure($config['password'] ?? []);

try {
    $result = (new DatabaseSeeder())->run(Database::connection());
    echo 'Database seeded successfully.' . PHP_EOL;
    echo 'User: ' . $result['user']['name'] . ' (' . $result['user']['username'] . ')' . PHP_EOL;
    echo 'Role: ' . $result['role'] . PHP_EOL;
    echo 'Permissions: ' . $result['permissions'] . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
