<?php

declare(strict_types=1);

namespace ExpressPHP\Database;

use PDO;
use RuntimeException;

final class Database
{
    private static array $config = [];
    private static array $connections = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$connections = [];
    }

    public static function connection(?string $name = null): PDO
    {
        $name ??= (string)(self::$config['default'] ?? 'mysql');

        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        $connection = self::$config['connections'][$name] ?? null;
        if (!is_array($connection)) {
            throw new RuntimeException("Database connection [{$name}] is not configured.");
        }

        $driver = (string)($connection['driver'] ?? 'mysql');
        $dsn = match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $connection['host'] ?? '127.0.0.1',
                $connection['port'] ?? 3306,
                $connection['database'] ?? '',
                $connection['charset'] ?? 'utf8mb4',
            ),
            'sqlite' => 'sqlite:' . ($connection['database'] ?? ':memory:'),
            default => throw new RuntimeException("Unsupported database driver [{$driver}]."),
        };

        $options = $connection['options'] ?? [];
        $options[PDO::ATTR_ERRMODE] ??= PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_DEFAULT_FETCH_MODE] ??= PDO::FETCH_ASSOC;
        $options[PDO::ATTR_EMULATE_PREPARES] ??= false;

        return self::$connections[$name] = new PDO(
            $dsn,
            (string)($connection['username'] ?? ''),
            (string)($connection['password'] ?? ''),
            $options,
        );
    }

    public static function disconnect(?string $name = null): void
    {
        if ($name === null) {
            self::$connections = [];
            return;
        }
        unset(self::$connections[$name]);
    }
}
