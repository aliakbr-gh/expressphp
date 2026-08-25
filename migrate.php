<?php

declare(strict_types=1);

use ExpressPHP\Database\Database;
use ExpressPHP\Database\Migrations\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

$config = require __DIR__ . '/config/app.php';
Database::configure($config['databases'] ?? []);

$command = $argv[1] ?? 'status';
$connection = $argv[2] ?? null;
$migrator = new Migrator(__DIR__ . '/migrations', $connection);

try {
    if ($command === 'status') {
        foreach ($migrator->status() as $migration) {
            echo str_pad($migration['status'], 10) . $migration['name'] . PHP_EOL;
        }
        exit;
    }

    $completed = match ($command) {
        'migrate' => $migrator->migrate(),
        'rollback' => $migrator->rollback(),
        default => throw new RuntimeException('Use: migrate, rollback, or status.'),
    };

    if ($completed === []) {
        echo 'Nothing to do.' . PHP_EOL;
        exit;
    }
    foreach ($completed as $migration) {
        echo strtoupper($command) . ': ' . $migration . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
