<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLog;
use ExpressPHP\Database\Database;
use ExpressPHP\Database\DatabaseBackup;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use RuntimeException;
use Throwable;

final class DatabaseBackupController
{
    public function __construct(private readonly ActivityLog $activities = new ActivityLog())
    {
    }

    public function download(Request $request, Response $response): Response
    {
        $request->validate([]);

        try {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            $connectionName = (string)($config['databases']['default'] ?? 'mysql');
            $connection = $config['databases']['connections'][$connectionName] ?? [];
            $database = (string)($connection['database'] ?? '');
            $backup = new DatabaseBackup(
                Database::connection(),
                $database,
                (string)($config['backups']['path'] ?? dirname(__DIR__, 2) . '/storage/backups'),
                (string)($config['timezone'] ?? 'UTC'),
            );
            $archive = $backup->createZip();
        } catch (Throwable) {
            return $response->error('Unable to create the database backup', 500);
        }

        $user = $request->user();
        $this->activities->record(
            $request,
            ($user['name'] ?? $user['username'] ?? 'User') . ' downloaded a database backup',
        );

        return $response
            ->header('Content-Type', 'application/zip')
            ->header('Content-Length', (string)filesize($archive['path']))
            ->attachment($archive['filename'])
            ->stream(static function () use ($archive): void {
                try {
                    $handle = fopen($archive['path'], 'rb');
                    if ($handle === false) {
                        throw new RuntimeException('Unable to open the backup archive.');
                    }
                    fpassthru($handle);
                    fclose($handle);
                } finally {
                    if (is_file($archive['path'])) {
                        unlink($archive['path']);
                    }
                    if (is_file($archive['sql_path'])) {
                        unlink($archive['sql_path']);
                    }
                }
            });
    }
}
