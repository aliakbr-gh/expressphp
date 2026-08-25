<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

use Closure;
use RuntimeException;

final class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $body = '';
    private ?Closure $streamCallback = null;

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function appendHeader(string $name, string $value): self
    {
        $current = $this->headers[$name] ?? null;
        $this->headers[$name] = $current === null ? $value : $current . ', ' . $value;
        return $this;
    }

    public function json(mixed $data, int $flags = 0): self
    {
        $this->header('Content-Type', 'application/json; charset=utf-8');
        $this->body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | $flags);
        return $this;
    }

    public function success(
        mixed  $data = null,
        string $message = 'Request successful',
        int    $status = 200,
    ): self {
        return $this->status($status)->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function error(
        string|array $message = 'Request failed',
        int          $status = 400,
    ): self {
        if (is_array($message) && count($message) === 1) {
            $message = reset($message);
        }

        return $this->status($status)->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ]);
    }

    public function text(string $text): self
    {
        $this->header('Content-Type', 'text/plain; charset=utf-8');
        $this->body = $text;
        return $this;
    }

    public function html(string $html): self
    {
        $this->header('Content-Type', 'text/html; charset=utf-8');
        $this->body = $html;
        return $this;
    }

    public function send(string $body, ?string $contentType = null): self
    {
        if ($contentType !== null) {
            $this->header('Content-Type', $contentType);
        }
        $this->body = $body;
        return $this;
    }

    public function cookie(string $name, string $value, array $options = []): self
    {
        $parts = [rawurlencode($name) . '=' . rawurlencode($value)];
        if (isset($options['expires'])) {
            $expires = $options['expires'] instanceof \DateTimeInterface
                ? $options['expires']->getTimestamp()
                : (int)$options['expires'];
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $expires);
        }
        if (isset($options['max_age'])) $parts[] = 'Max-Age=' . (int)$options['max_age'];
        $parts[] = 'Path=' . ($options['path'] ?? '/');
        if (isset($options['domain'])) $parts[] = 'Domain=' . $options['domain'];
        if (($options['secure'] ?? false) === true) $parts[] = 'Secure';
        if (($options['http_only'] ?? true) === true) $parts[] = 'HttpOnly';
        if (isset($options['same_site'])) $parts[] = 'SameSite=' . $options['same_site'];
        return $this->appendHeader('Set-Cookie', implode('; ', $parts));
    }

    public function clearCookie(string $name, array $options = []): self
    {
        return $this->cookie($name, '', [...$options, 'expires' => 1, 'max_age' => 0]);
    }

    public function redirect(string $url, int $status = 302): self
    {
        return $this->status($status)->header('Location', $url)->html(
            '<!doctype html><title>Redirecting</title><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Redirecting</a>'
        );
    }

    public function attachment(string $filename): self
    {
        $safe = str_replace(["\r", "\n", '"'], '', basename($filename));
        return $this->header('Content-Disposition', 'attachment; filename="' . $safe . '"');
    }

    public function sendFile(string $path, ?string $downloadName = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException('File not found', 404);
        }
        $type = function_exists('mime_content_type') ? mime_content_type($path) : false;
        $this->header('Content-Type', $type ?: 'application/octet-stream');
        $this->header('Content-Length', (string)filesize($path));
        if ($downloadName !== null) {
            $this->attachment($downloadName);
        }
        return $this->stream(static function () use ($path): void {
            $handle = fopen($path, 'rb');
            if ($handle === false) throw new RuntimeException('Unable to open response file.');
            fpassthru($handle);
            fclose($handle);
        });
    }

    public function download(string $path, ?string $filename = null): self
    {
        return $this->sendFile($path, $filename ?? basename($path));
    }

    public function stream(callable $callback, string $contentType = 'application/octet-stream'): self
    {
        if (!isset($this->headers['Content-Type'])) {
            $this->header('Content-Type', $contentType);
        }
        $this->streamCallback = Closure::fromCallable($callback);
        $this->body = '';
        return $this;
    }

    public function noContent(): self
    {
        $this->statusCode = 204;
        $this->body = '';
        return $this;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function emit(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->streamCallback !== null) {
            ($this->streamCallback)();
            return;
        }
        echo $this->body;
    }
}
