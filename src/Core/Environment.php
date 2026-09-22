<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

use RuntimeException;

final class Environment
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read the environment file.");
        }

        foreach (preg_split("/\R/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                throw new RuntimeException('Invalid environment line.');
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) !== 1) {
                throw new RuntimeException("Invalid environment variable [{$name}].");
            }
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) !== false) {
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
