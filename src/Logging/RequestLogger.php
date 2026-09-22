<?php

declare(strict_types=1);

namespace ExpressPHP\Logging;

use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class RequestLogger
{
    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'api_key',
    ];

    private static array $config = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    public static function write(Request $request, Response $response): void
    {
        if (!(bool)(self::$config['enabled'] ?? true)) {
            return;
        }

        try {
            $timezone = new DateTimeZone((string)(self::$config['timezone'] ?? 'UTC'));
            $now = new DateTimeImmutable('now', $timezone);
            $directory = rtrim(
                (string)(self::$config['path'] ?? dirname(__DIR__, 2) . '/storage/logs'),
                '/',
            );

            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                return;
            }

            $startedAt = defined('APP_STARTED_AT')
                ? (float)APP_STARTED_AT
                : (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

            $entry = [
                'timestamp' => $now->format(DATE_ATOM),
                'request_id' => $request->header('X-Request-ID') ?? bin2hex(random_bytes(8)),
                'method' => $request->method(),
                'url' => $request->originalURL(),
                'path' => $request->path(),
                'status' => $response->statusCode(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                'ip' => $request->ip(),
                'user_id' => $request->user()['id'] ?? null,
                'user_agent' => $request->userAgent(),
                'query' => self::redact($request->query()),
                'input' => self::input($request),
                'request_bytes' => strlen($request->text()),
                'response_bytes' => strlen($response->body()),
                'memory_peak_bytes' => memory_get_peak_usage(true),
            ];

            $json = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents(
                $directory . '/' . $now->format('Y-m-d') . '.log',
                $json . PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            // Request logging is best-effort and must never break the application.
        }
    }

    public static function directory(): string
    {
        return rtrim(
            (string)(self::$config['path'] ?? dirname(__DIR__, 2) . '/storage/logs'),
            '/',
        );
    }

    public static function currentDate(): string
    {
        $timezone = new DateTimeZone((string)(self::$config['timezone'] ?? 'UTC'));
        return (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    }

    private static function input(Request $request): mixed
    {
        $limit = max(0, (int)(self::$config['max_input_bytes'] ?? 4096));
        if ($limit === 0 || strlen($request->text()) > $limit) {
            return $request->text() === '' ? null : '[omitted: payload too large]';
        }

        try {
            return self::redact($request->input());
        } catch (JsonException) {
            return '[omitted: malformed JSON]';
        } catch (Throwable) {
            return '[omitted: unreadable payload]';
        }
    }

    private static function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) && strlen($value) > 1000
                ? substr($value, 0, 1000) . '[truncated]'
                : $value;
        }

        foreach ($value as $key => $item) {
            $normalized = strtolower((string)$key);
            $value[$key] = in_array($normalized, self::SENSITIVE_KEYS, true)
                ? '[redacted]'
                : self::redact($item);
        }

        return $value;
    }
}
