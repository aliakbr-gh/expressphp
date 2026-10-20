<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * Case-exact file checks, so code behaves the same on every OS.
 *
 * macOS and Windows file systems ignore letter case by default, Linux doesn't: is_file('Example.php')
 * finds example.php on a Mac but not on a Linux server. Checking the real names makes a wrong case
 * fail locally too, instead of only after deploying.
 *
 * Loaded by core/bootstrap.php before the autoloader (which uses it).
 */
final class Path
{
    /** @var array<string, array<string, true>> directory => its entry names, read once per request */
    private static array $entries = [];

    /**
     * True when $base/$relative is a file and every segment of $relative has exactly that case.
     * $relative uses "/" between segments, e.g. 'Controllers/UserController.php'.
     */
    public static function isFileExact(string $base, string $relative): bool
    {
        $base = rtrim($base, '/\\');
        if (!is_file($base . '/' . $relative)) {
            return false;
        }

        $dir = $base;
        foreach (explode('/', $relative) as $segment) {
            if (!isset(self::entries($dir)[$segment])) {
                return false;
            }
            $dir .= '/' . $segment;
        }

        return true;
    }

    /** Entry names of a directory as a lookup set; empty when it can't be read. */
    private static function entries(string $dir): array
    {
        if (!isset(self::$entries[$dir])) {
            $names = @scandir($dir);
            self::$entries[$dir] = $names === false ? [] : array_fill_keys($names, true);
        }

        return self::$entries[$dir];
    }
}
