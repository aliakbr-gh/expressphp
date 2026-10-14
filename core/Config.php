<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Read-only access to config/config.php using dot notation.
 */
final class Config
{
    private static ?array $items = null;

    /** Loads config/config.php once at startup (called from core/bootstrap.php). */
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException("Config file not found: {$file}");
        }

        $items = require $file;

        if (!is_array($items)) {
            throw new RuntimeException('Config file must return an array.');
        }

        self::$items = $items;
    }

    /** Reads a value with dot notation, e.g. get('database.host'); returns $default when missing. */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$items === null) {
            throw new RuntimeException('Config has not been loaded.');
        }

        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Overrides a value for the current request only (e.g. in tests); never written to disk. */
    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /** True when the key exists (even if its value is null). */
    public static function has(string $key): bool
    {
        return self::get($key, $marker = new \stdClass()) !== $marker;
    }
}
