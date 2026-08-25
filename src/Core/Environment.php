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

        $values = parse_ini_file($path, false, INI_SCANNER_RAW);
        if ($values === false) {
            throw new RuntimeException("Unable to parse environment file [{$path}].");
        }

        foreach ($values as $name => $value) {
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/', (string)$name) !== 1) {
                throw new RuntimeException("Invalid environment variable [{$name}].");
            }

            if (getenv((string)$name) !== false) {
                continue;
            }

            $value = (string)$value;
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
