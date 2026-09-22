<?php

declare(strict_types=1);

namespace ExpressPHP\Database;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use ZipArchive;

final class DatabaseBackup
{
    public function __construct(
        private readonly PDO $connection,
        private readonly string $database,
        private readonly string $path,
        private readonly string $timezone,
    ) {
    }

    /**
     * @return array{path: string, sql_path: string, filename: string}
     */
    public function createZip(): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The Zip PHP extension is required for database backups.');
        }

        $this->ensureDirectory();
        $stamp = (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('Y-m-d-h-i-A');
        $safeDatabase = preg_replace('/[^A-Za-z0-9_-]/', '', $this->database) ?: 'database';
        $basename = $safeDatabase . '-' . $stamp;
        $token = bin2hex(random_bytes(16));
        $sqlPath = $this->path . '/' . $token . '.sql';
        $zipPath = $this->path . '/' . $token . '.zip';
        $filename = $basename . '.zip';

        try {
            $this->writeSql($sqlPath, $basename . '.sql', $stamp);
            $archive = new ZipArchive();
            if ($archive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the backup archive.');
            }
            if ($archive->addFile($sqlPath, $basename . '.sql') !== true) {
                $archive->close();
                throw new RuntimeException('Unable to add the backup file to the archive.');
            }
            $archive->close();
        } catch (RuntimeException $exception) {
            $this->delete($sqlPath);
            $this->delete($zipPath);
            throw $exception;
        }

        return [
            'path' => $zipPath,
            'sql_path' => $sqlPath,
            'filename' => $filename,
        ];
    }

    private function writeSql(string $sqlPath, string $sqlName, string $stamp): void
    {
        $handle = fopen($sqlPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create the backup file.');
        }

        try {
            $this->write(
                $handle,
                "-- ExpressPHP database backup\n" .
                '-- File: ' . $sqlName . "\n" .
                '-- Created: ' . $stamp . "\n\n" .
                "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n",
            );

            foreach ($this->tables() as $table) {
                $quoted = $this->quoteName($table);
                $create = $this->connection->query('SHOW CREATE TABLE ' . $quoted)->fetch();
                $statement = is_array($create) ? (string)($create['Create Table'] ?? $create[1] ?? '') : '';
                if ($statement === '') {
                    throw new RuntimeException('Unable to read the table structure.');
                }

                $this->write(
                    $handle,
                    'DROP TABLE IF EXISTS ' . $quoted . ";\n" . $statement . ";\n\n",
                );
                $this->writeRows($handle, $table, $quoted);
                $this->write($handle, "\n");
            }

            $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    private function writeRows($handle, string $table, string $quoted): void
    {
        $offset = 0;
        $chunk = 200;

        while (true) {
            $statement = $this->connection->query(
                'SELECT * FROM ' . $quoted . ' LIMIT ' . $chunk . ' OFFSET ' . $offset,
            );
            $rows = $statement === false ? [] : $statement->fetchAll();
            if ($rows === []) {
                return;
            }

            $columns = array_map($this->quoteName(...), array_keys($rows[0]));
            $columnList = implode(', ', $columns);

            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = $this->sqlValue($value);
                }
                $this->write(
                    $handle,
                    'INSERT INTO ' . $quoted . ' (' . $columnList . ') VALUES (' . implode(', ', $values) . ");\n",
                );
            }

            $offset += $chunk;
        }
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        $quoted = $this->connection->quote((string)$value);
        if ($quoted === false) {
            throw new RuntimeException('Unable to encode a backup value.');
        }

        return $quoted;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $statement = $this->connection->query(
            "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'",
        );
        $tables = [];

        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            $name = array_values($row)[0] ?? null;
            if (is_string($name) && $name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    private function quoteName(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('Unsafe database identifier.');
        }

        return '`' . $name . '`';
    }

    private function write($handle, string $sql): void
    {
        if (fwrite($handle, $sql) === false) {
            throw new RuntimeException('Unable to write the backup file.');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->path) && !mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new RuntimeException('Unable to create the backup storage directory.');
        }
    }

    private function delete(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
