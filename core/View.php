<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

/**
 * Plain-PHP templates stored in app/Views/. Controllers normally use $response->view() instead:
 *
 *   return $response->view('example', ['title' => 'Hello', 'user' => $request->user()]);
 *
 * Names use dots for subfolders: 'users.profile' => app/Views/users/profile.php.
 * Each key of $data becomes a variable inside the template ($title, $user ...).
 * Escape every value you print with e(): <?= e($user['name']) ?>
 */
final class View
{
    /** Renders a view and returns the HTML as a string. */
    public static function render(string $view, array $data = []): string
    {
        $file = self::path($view);

        // Rendered inside a static closure so the template only sees $data (no $this, no locals).
        $render = static function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        };

        $level = ob_get_level();
        ob_start();

        try {
            $render($file, $data);
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            // Discard partial output (including nested buffers the template opened) before rethrowing.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    /** True when app/Views/<name>.php exists. */
    public static function exists(string $view): bool
    {
        try {
            self::path($view);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** Resolves 'users.profile' to app/Views/users/profile.php; rejects names that could leave the folder. */
    private static function path(string $view): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $view) !== 1) {
            throw new RuntimeException("Invalid view name: {$view}");
        }

        $file = BASE_PATH . '/app/Views/' . str_replace('.', '/', $view) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$view}");
        }

        return $file;
    }
}
