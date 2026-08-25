<?php

declare(strict_types=1);

namespace ExpressPHP\Auth;

use RuntimeException;

final class Password
{
    private static int $bcryptCost = 10;

    public static function configure(array $config): void
    {
        $cost = (int)($config['bcrypt_cost'] ?? 10);
        if ($cost < 4 || $cost > 31) {
            throw new RuntimeException('Password bcrypt cost must be between 4 and 31.');
        }

        self::$bcryptCost = $cost;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => self::$bcryptCost,
        ]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, [
            'cost' => self::$bcryptCost,
        ]);
    }
}
