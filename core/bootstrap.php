<?php

declare(strict_types=1);

/**
 * Single entry point for loading the backend: autoloader, config, error handling.
 * Every script (API front controller, tests, CLI tools) should require this file.
 */

// Project root (this file lives in core/).
define('BASE_PATH', dirname(__DIR__));

// PSR-4 autoloader:
//   App\Controllers\UserController => app/Controllers/UserController.php  (your application code)
//   Core\Router                    => core/Router.php                     (framework internals)
spl_autoload_register(static function (string $class): void {
    $namespaces = [
        'App\\'  => BASE_PATH . '/app/',
        'Core\\' => BASE_PATH . '/core/',
    ];

    foreach ($namespaces as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

// Global helper functions such as dd().
require BASE_PATH . '/core/helpers.php';

Core\Config::load(BASE_PATH . '/config/config.php');

date_default_timezone_set(Core\Config::get('app.timezone', 'UTC'));

$debug = (bool) Core\Config::get('app.debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

// PHP's own messages (e.g. startup errors) go to the same daily file, or nowhere when logging is off.
if (Core\Log::enabled()) {
    ini_set('log_errors', '1');
    ini_set('error_log', Core\Log::file());
} else {
    ini_set('log_errors', '0');
}

// Convert warnings/notices into exceptions so nothing fails silently.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    // Deprecations only warn about a future PHP version: log them instead of failing the request,
    // so upgrading PHP never turns working endpoints into 500s.
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        Core\Log::warning("Deprecated: {$message} at " . str_replace(BASE_PATH . '/', '', $file) . ":{$line}");
        return true;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Fatal errors (out of memory, timeouts) can't be caught; record them on shutdown.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        Core\Log::error("Fatal: {$error['message']} at {$error['file']}:{$error['line']}");
    }
});
