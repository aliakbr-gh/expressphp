<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

use ExpressPHP\Validation\Validator;
use JsonException;

final class Request
{
    private array $params = [];
    private bool $jsonDecoded = false;
    private mixed $decodedJson = null;
    private ?array $parsedForm = null;
    private ?array $authenticatedUser = null;

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array  $query = [],
        private readonly array  $form = [],
        private readonly array  $server = [],
        private readonly array  $files = [],
        private readonly array  $cookies = [],
        private readonly string $rawBody = '',
        private readonly string $basePath = '',
        private readonly array  $trustedProxies = [],
    ) {
    }

    public static function capture(array $trustedProxies = []): self
    {
        $rawBody = file_get_contents('php://input');
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim(dirname($scriptName), '/.');

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $_SERVER['REQUEST_URI'] ?? '/',
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES,
            $_COOKIE,
            $rawBody === false ? '' : $rawBody,
            $basePath === '' ? '' : '/' . trim($basePath, '/'),
            $trustedProxies,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH) ?: '/';

        if ($this->basePath !== '' && ($path === $this->basePath || str_starts_with($path, $this->basePath . '/'))) {
            $path = substr($path, strlen($this->basePath)) ?: '/';
        }

        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function text(): string
    {
        return $this->rawBody;
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->jsonDecoded) {
            try {
                $this->decodedJson = $this->rawBody === ''
                    ? []
                    : json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new HttpException('Malformed JSON request body', 400);
            }
            $this->jsonDecoded = true;
        }

        if ($key === null) {
            return $this->decodedJson;
        }

        return is_array($this->decodedJson) ? ($this->decodedJson[$key] ?? $default) : $default;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->isJson() ? $this->json() : $this->formData();

        if ($key === null) {
            return $data;
        }

        return is_array($data) ? ($data[$key] ?? $default) : $default;
    }

    public function validate(array $rules, array $messages = []): array
    {
        $input = $this->input();
        $input = is_array($input) ? $input : [];
        return Validator::validate(array_replace_recursive($this->query, $input), $rules, $messages);
    }

    public function validateParams(array $rules, array $messages = []): array
    {
        return Validator::validate(array_replace_recursive($this->query, $this->params), $rules, $messages);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function param(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->params : ($this->params[$key] ?? $default);
    }

    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (strtolower($name) === 'content-type') {
            $key = 'CONTENT_TYPE';
        }

        if (strtolower($name) === 'authorization') {
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $authorizationKey) {
                if (isset($this->server[$authorizationKey]) && $this->server[$authorizationKey] !== '') {
                    return (string)$this->server[$authorizationKey];
                }
            }
        }

        return isset($this->server[$key]) ? (string)$this->server[$key] : $default;
    }

    public function headers(): array
    {
        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string)$value;
            }
        }
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
            if (isset($this->server[$key])) {
                $headers[$name] = (string)$this->server[$key];
            }
        }
        return $headers;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type', '') ?? ''), 'application/json');
    }

    public function files(): array
    {
        return $this->files;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        return is_array($file) ? $file : null;
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function ip(): string
    {
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        if (in_array($remote, $this->trustedProxies, true)) {
            $forwarded = $this->header('X-Forwarded-For');
            if ($forwarded !== null) {
                return trim(explode(',', $forwarded)[0]);
            }
        }
        return $remote;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->header('Authorization');
        return $authorization !== null && preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1
            ? trim($match[1])
            : null;
    }

    public function basicCredentials(): ?array
    {
        if (isset($this->server['PHP_AUTH_USER'], $this->server['PHP_AUTH_PW'])) {
            return [
                'username' => (string)$this->server['PHP_AUTH_USER'],
                'password' => (string)$this->server['PHP_AUTH_PW'],
            ];
        }

        $authorization = $this->header('Authorization')
            ?? (isset($this->server['REDIRECT_HTTP_AUTHORIZATION'])
                ? (string)$this->server['REDIRECT_HTTP_AUTHORIZATION']
                : null);

        if ($authorization === null || preg_match('/^Basic\s+(.+)$/i', $authorization, $match) !== 1) {
            return null;
        }

        $decoded = base64_decode(trim($match[1]), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);
        return ['username' => $username, 'password' => $password];
    }

    public function setUser(array $user): self
    {
        $this->authenticatedUser = $user;
        return $this;
    }

    public function user(): ?array
    {
        return $this->authenticatedUser;
    }

    public function userAgent(): ?string
    {
        return $this->header('User-Agent');
    }

    public function protocol(): string
    {
        $remote = (string)($this->server['REMOTE_ADDR'] ?? '');
        if (in_array($remote, $this->trustedProxies, true)) {
            $forwarded = $this->header('X-Forwarded-Proto');
            if ($forwarded !== null) {
                return strtolower(trim(explode(',', $forwarded)[0]));
            }
        }
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    public function secure(): bool
    {
        return $this->protocol() === 'https';
    }

    public function host(): string
    {
        return $this->header('Host', (string)($this->server['SERVER_NAME'] ?? 'localhost')) ?? 'localhost';
    }

    public function hostname(): string
    {
        return explode(':', $this->host())[0];
    }

    public function url(): string
    {
        return $this->protocol() . '://' . $this->host() . $this->uri;
    }

    public function originalUrl(): string
    {
        return $this->uri;
    }

    public function accepts(string ...$types): string|false
    {
        $accept = strtolower($this->header('Accept', '*/*') ?? '*/*');
        foreach ($types as $type) {
            $candidate = strtolower($type);
            $mime = str_contains($candidate, '/') ? $candidate : match ($candidate) {
                'json' => 'application/json',
                'html' => 'text/html',
                'text' => 'text/plain',
                default => $candidate,
            };
            if ($accept === '*/*' || str_contains($accept, $mime) || str_contains($accept, strtok($mime, '/') . '/*')) {
                return $type;
            }
        }
        return false;
    }

    private function formData(): array
    {
        if ($this->form !== []) {
            return $this->form;
        }
        if ($this->parsedForm !== null) {
            return $this->parsedForm;
        }
        $this->parsedForm = [];
        $contentType = strtolower($this->header('Content-Type', '') ?? '');
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($this->rawBody, $this->parsedForm);
        }
        return $this->parsedForm;
    }
}
