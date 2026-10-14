<?php

declare(strict_types=1);

namespace Core;

use Throwable;

/**
 * Daily log files: storage/logs/app-2026-10-14.log
 *
 *   Log::info('User registered', ['id' => 5]);
 *   Log::warning('Login failed', ['email' => $email]);
 *   Log::error('Payment failed', ['order' => 12]);
 *   Log::exception($e);
 *
 * One line per entry, appended with a single write — no buffering, no extra I/O.
 * Turned on/off with logging.enabled in config/config.php.
 */
final class Log
{
    /** Normal events, e.g. "User registered". */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /** Something unexpected that the app handled, e.g. a failed login. */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /** Something failed and needs attention. */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * "LogicException: Something broke at app/Controllers/UserController.php:42"
     * Data values quoted in database error messages are masked (see maskDataValues()).
     */
    public static function exception(Throwable $e): void
    {
        $origin  = $e->getPrevious() ?? $e;
        $message = self::maskDataValues($origin->getMessage());

        self::error(sprintf('%s: %s at %s:%d', $origin::class, $message, self::relative($origin->getFile()), $origin->getLine()));
    }

    /**
     * Replaces data values that MySQL quotes in its error messages with '***', keeping the
     * column and index names. The values come from the request (an email, a token, a password
     * sent to the wrong column) and don't belong in a log file:
     *   Duplicate entry 'ali@x.com' for key 'users.users_email_unique'  => Duplicate entry '***' for key ...
     *   Incorrect integer value: 'abc' for column 'age' at row 1         => ... value: '***' for column 'age' ...
     *   Truncated incorrect DOUBLE value: 'abc'                          => ... value: '***'
     */
    public static function maskDataValues(string $message): string
    {
        $masked = preg_replace(
            [
                "/Duplicate entry '.*?' for key/s",
                "/value: '.*?'(?= for column | at row |$)/s",
            ],
            [
                "Duplicate entry '***' for key",
                "value: '***'",
            ],
            $message
        );

        return $masked ?? $message;
    }

    /** "GET /api/v1/users 200 12.4ms 127.0.0.1" — level follows the status code. */
    public static function request(Request $request, int $status, float $startedAt): void
    {
        $level = match (true) {
            $status >= 500 => 'ERROR',
            $status >= 400 => 'WARNING',
            default        => 'INFO',
        };

        self::write($level, sprintf(
            '%s %s %d %.1fms %s',
            $request->method,
            $request->path,
            $status,
            (microtime(true) - $startedAt) * 1000,
            $request->ip()
        ));
    }

    /** Value of logging.enabled in config/config.php. */
    public static function enabled(): bool
    {
        return (bool) Config::get('logging.enabled', false);
    }

    /** Today's log file path. */
    public static function file(): string
    {
        return rtrim(Config::get('logging.path'), '/') . '/app-' . date('Y-m-d') . '.log';
    }

    /** Formats one line and appends it to today's file. Does nothing when logging is disabled. */
    private static function write(string $level, string $message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . "] {$level} " . self::escape($message);
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        // Logging must never break a request, so a failed write (e.g. permissions) is ignored.
        @file_put_contents(self::file(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Makes control characters visible ("\x0A") so one entry always stays on one line.
     * Messages can contain user input (URL paths, exception messages); without this, a request
     * to /x%0A[2026-01-01 00:00:00] ERROR ... would write a forged second log line.
     */
    private static function escape(string $message): string
    {
        return preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn(array $m): string => sprintf('\x%02X', ord($m[0])),
            $message
        ) ?? '';
    }

    /** Shortens absolute paths to project-relative ones (app/... instead of /var/www/...). */
    private static function relative(string $path): string
    {
        return str_starts_with($path, BASE_PATH . '/') ? substr($path, strlen(BASE_PATH) + 1) : $path;
    }
}
