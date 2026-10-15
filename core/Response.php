<?php

declare(strict_types=1);

namespace Core;

use Error;
use RuntimeException;

/**
 * HTTP response. Every controller receives one as its second argument:
 *
 *   public function show(Request $request, Response $response): Response
 *   {
 *       return $response->success($user, 'User fetched.');
 *   }
 *
 * Response shapes:
 *   success: { "success": true,  "message": "...", "data": ... }
 *   error:   { "success": false, "message": "...", "errors": ... | null }
 *
 *   $response->success($data, 'Message')                    200
 *   $response->success($data, 'Message', 201)               any status code
 *   $response->paginate($items, $total, $page, $perPage)    data = { items, pagination }
 *   $response->error('Message', 404, $errors)
 *   $response->noContent()                                  204, empty body
 *   $response->download($path, 'name.pdf')
 *   $response->view('example', ['title' => 'Hi'])          HTML page rendered from app/Views/example.php
 *   $response->json($anything)                              escape hatch: raw JSON, bypasses the envelope
 *
 * Chainable: ->header(), ->withHeaders(), ->cookie(), ->withoutCookie(), ->setStatusCode()
 * Read-only properties: $response->status, $response->body, $response->headers
 * (private, exposed through __get() so this works on PHP 8.1+)
 *
 * @property-read int   $status
 * @property-read mixed $body
 * @property-read array $headers
 */
final class Response
{
    private const READ_ONLY = ['status', 'body', 'headers'];

    private int $status;
    private mixed $body;
    private array $headers = [];

    private array $cookies = [];
    private ?string $filePath = null;
    private bool $html = false;

    /** Controllers receive a ready-made instance; build one directly only outside controllers. */
    public function __construct(mixed $body = null, int $status = 200, array $headers = [])
    {
        $this->body   = $body;
        $this->status = $status;
        $this->withHeaders($headers);
    }

    // ------------------------------------------------------------------ Content

    /** { success: true, message, data } */
    public function success(mixed $data = null, string $message = 'OK', int $status = 200): self
    {
        return $this->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    /** { success: false, message, errors } */
    public function error(string $message, int $status = 400, mixed $errors = null): self
    {
        return $this->json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }

    /** Success response where data = { items, pagination }. $page is 1-based. */
    public function paginate(array $items, int $total, int $page, int $perPage, string $message = 'OK'): self
    {
        $perPage  = max(1, $perPage);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $from     = $total === 0 || $items === [] ? null : ($page - 1) * $perPage + 1;

        return $this->success([
            'items'      => array_values($items),
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => $lastPage,
                'from'         => $from,
                'to'           => $from === null ? null : $from + count($items) - 1,
            ],
        ], $message);
    }

    /** 204 No Content: empty body (e.g. after a delete). */
    public function noContent(): self
    {
        return $this->json(null, 204);
    }

    /** Raw JSON that bypasses the standard envelope — only for special cases (e.g. third-party webhooks). */
    public function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $this->body     = $data;
        $this->status   = $status;
        $this->filePath = null;
        $this->html     = false;
        return $this->withHeaders($headers);
    }

    /**
     * HTML page rendered from app/Views/<name>.php; each key of $data becomes a variable in the view.
     * 'users.profile' => app/Views/users/profile.php. The body is the rendered HTML string.
     */
    public function view(string $view, array $data = [], int $status = 200, array $headers = []): self
    {
        $this->body     = View::render($view, $data);
        $this->status   = $status;
        $this->filePath = null;
        $this->html     = true;
        return $this->withHeaders($headers);
    }

    /** Sends a file as an attachment. */
    public function download(string $path, ?string $name = null, array $headers = []): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException(404, 'File not found.');
        }

        $name     = $name ?? basename($path);
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        $this->body     = null;
        $this->status   = 200;
        $this->filePath = $path;
        $this->html     = false;

        return $this->withHeaders($headers + [
            'Content-Type'        => (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream',
            'Content-Length'      => (string) filesize($path),
            'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode($name)),
        ]);
    }

    // ------------------------------------------------------------------ Modifiers

    /** Changes the HTTP status code. */
    public function setStatusCode(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    /** Adds or replaces one response header. */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /** Adds several headers at once: withHeaders(['X-A' => '1']). */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header($name, (string) $value);
        }
        return $this;
    }

    /** $minutes = 0 makes a session cookie. $secure = null => secure when the request is HTTPS. */
    public function cookie(
        string $name,
        string $value,
        int $minutes = 0,
        string $path = '/',
        string $domain = '',
        ?bool $secure = null,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        $this->cookies[$name] = [$value, [
            'expires'  => $minutes === 0 ? 0 : time() + $minutes * 60,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure ?? (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off'),
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ]];
        return $this;
    }

    /** Tells the browser to delete a cookie (expires it in the past). */
    public function withoutCookie(string $name, string $path = '/', string $domain = ''): self
    {
        $this->cookies[$name] = ['', ['expires' => time() - 3600, 'path' => $path, 'domain' => $domain]];
        return $this;
    }

    // ------------------------------------------------------------------ Read-only properties

    /** $response->status, ->body, ->headers. */
    public function __get(string $key): mixed
    {
        if (!in_array($key, self::READ_ONLY, true)) {
            throw new Error('Undefined property ' . self::class . '::$' . $key);
        }

        return $this->{$key};
    }

    public function __isset(string $key): bool
    {
        return in_array($key, self::READ_ONLY, true) && isset($this->{$key});
    }

    /** Read-only: use the methods above (setStatusCode(), header(), success() ...) instead. */
    public function __set(string $key, mixed $value): void
    {
        throw new Error('Cannot modify read-only property ' . self::class . '::$' . $key);
    }

    public function __unset(string $key): void
    {
        throw new Error('Cannot unset read-only property ' . self::class . '::$' . $key);
    }

    // ------------------------------------------------------------------ Output

    /**
     * Writes status, headers, cookies and body to the client. Called once by public/index.php.
     * Throws (before anything is sent) if the body can't be encoded as JSON.
     */
    public function send(): void
    {
        // Encode first: if this fails, no status or header has gone out yet, so the caller can still
        // answer with a clean 500. Invalid UTF-8 (e.g. raw bytes in a MySQL error) becomes U+FFFD "�"
        // instead of failing; INF/NaN or extreme nesting still throw.
        $json = null;
        if ($this->filePath === null && !$this->html && $this->status !== 204) {
            $json = json_encode(
                $this->body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        }

        if (!headers_sent()) {
            http_response_code($this->status);

            if ($this->html) {
                header('Content-Type: text/html; charset=utf-8');
            } elseif ($this->filePath === null && $this->status !== 204) {
                header('Content-Type: application/json; charset=utf-8');
            }
            header('X-Content-Type-Options: nosniff');

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
            foreach ($this->cookies as $name => [$value, $options]) {
                setcookie($name, $value, $options);
            }
        }

        if ($this->filePath !== null) {
            if (readfile($this->filePath) === false) {
                throw new RuntimeException('Failed to read file for download.');
            }
            return;
        }

        if ($this->html) {
            echo $this->body;
        } elseif ($json !== null) {
            echo $json;
        }
    }
}
