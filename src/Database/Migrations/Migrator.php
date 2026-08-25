<?php

declare(strict_types=1);

namespace ExpressPHP\Database\Migrations;

use ExpressPHP\Database\Database;
use PDO;
use RuntimeException;

final class Migrator
{
    private PDO $database;

    public function __construct(
        private readonly string $path,
        ?string                 $connection = null,
    ) {
        $this->database = Database::connection($connection);
        $this->createRepository();
    }

    public function migrate(): array
    {
        $ran = $this->ran();
        $batch = $this->nextBatch();
        $completed = [];

        foreach ($this->files() as $name => $file) {
            if (isset($ran[$name])) {
                continue;
            }
            $migration = require $file;
            if (!$migration instanceof Migration) {
                throw new RuntimeException("Migration [{$name}] must return a Migration instance.");
            }
            $migration->up($this->database);
            $statement = $this->database->prepare(
                'INSERT INTO migrations (migration, batch, migrated_at) VALUES (:migration, :batch, CURRENT_TIMESTAMP)'
            );
            $statement->execute(['migration' => $name, 'batch' => $batch]);
            $completed[] = $name;
        }
        return $completed;
    }

    public function rollback(): array
    {
        $batch = (int)$this->database->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();
        if ($batch === 0) {
            return [];
        }
        $statement = $this->database->prepare('SELECT migration FROM migrations WHERE batch = :batch ORDER BY id DESC');
        $statement->execute(['batch' => $batch]);
        $rolledBack = [];

        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $file = $this->files()[$name] ?? null;
            if ($file === null) {
                throw new RuntimeException("Migration file [{$name}] is missing.");
            }
            $migration = require $file;
            $migration->down($this->database);
            $delete = $this->database->prepare('DELETE FROM migrations WHERE migration = :migration');
            $delete->execute(['migration' => $name]);
            $rolledBack[] = $name;
        }
        return $rolledBack;
    }

    public function status(): array
    {
        $ran = $this->ran();
        $status = [];
        foreach ($this->files() as $name => $_file) {
            $status[] = ['name' => $name, 'status' => isset($ran[$name]) ? 'Ran' : 'Pending'];
        }
        return $status;
    }

    private function createRepository(): void
    {
        $this->database->exec(
            'CREATE TABLE IF NOT EXISTS migrations (' .
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
            'migration VARCHAR(255) NOT NULL UNIQUE, ' .
            'batch INT UNSIGNED NOT NULL, ' .
            'migrated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function files(): array
    {
        $files = glob(rtrim($this->path, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $mapped = [];
        foreach ($files as $file) {
            $mapped[pathinfo($file, PATHINFO_FILENAME)] = $file;
        }
        return $mapped;
    }

    private function ran(): array
    {
        $names = $this->database->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys($names, true);
    }

    private function nextBatch(): int
    {
        return (int)$this->database->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations')->fetchColumn();
    }
}
