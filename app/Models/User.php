<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

final class User
{
    private const COLUMNS = 'id, name, username, created_at, updated_at';

    public static function create(string $name, string $username, string $passwordHash): int
    {
        Database::execute(
            'INSERT INTO users (name, username, password) VALUES (?, ?, ?)',
            [$name, $username, $passwordHash]
        );

        return Database::lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        return Database::fetch('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    public static function findByUsernameWithPassword(string $username): ?array
    {
        return Database::fetch(
            'SELECT ' . self::COLUMNS . ', password FROM users WHERE username = ? LIMIT 1',
            [$username]
        );
    }
}
