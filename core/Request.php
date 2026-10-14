<?php

declare(strict_types=1);

namespace Core;

use RequestParseBodyException;

/**
 * The current HTTP request.
 *
 * Read-only properties:
 *   $request->method   'GET', 'POST', ...
 *   $request->path     '/api/v1/users/5'
 *   $request->body     parsed JSON / form body (POST, PUT, PATCH, DELETE)
 *   $request->query    query string (?page=2)
 *   $request->params   route params ({id})
 *   $request->headers  lower-cased header names
 *   $request->files    UploadedFile objects
 *   $request->cookies
 *
 * Shortcut: $request->email looks in route params, then body, then query (like Laravel).
 */
final class Request
{
    public private(set) string $method;
    public private(set) string $path;
    public private(set) array $query;
    public private(set) array $body;
    public private(set) array $params = [];
    public private(set) array $headers;
    public private(set) array $files;
    public private(set) array $cookies;

    private array $attributes = [];

    /** True when PHP itself rejected or truncated a POST body before the app ran (see fromGlobals()). */
    private bool $phpRejectedBody = false;

    /** Use Request::capture() for the real request or Request::create() in tests. */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = [],
        array $files = [],
        array $cookies = [],
        private readonly array $server = [],
        private readonly string $basePath = '',
    ) {
        $this->method  = strtoupper($method);
        $this->path    = self::normalizePath($path);
        $this->query   = $query;
        $this->body    = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->files   = $files;
        $this->cookies = $cookies;
    }

    // ------------------------------------------------------------------ Creation

    /**
     * Builds the full request from PHP globals: fromGlobals() + readBody().
     * Throws HttpException 400/413 for a malformed or oversized body.
     */
    public static function capture(): self
    {
        $request = self::fromGlobals();
        $request->readBody();

        return $request;
    }

    /**
     * Builds the request from PHP globals **without** reading the body (body and files are empty).
     * Never throws, so public/index.php can rate-limit and log a request even when its body
     * turns out to be malformed or too large; call readBody() afterwards.
     */
    public static function fromGlobals(): self
    {
        $server  = $_SERVER;
        $headers = self::extractHeaders($server);
        $method  = strtoupper($server['REQUEST_METHOD'] ?? 'GET');

        // Clients that can only send POST may override the verb with this header.
        if ($method === 'POST' && isset($headers['x-http-method-override'])) {
            $override = strtoupper($headers['x-http-method-override']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        [$basePath, $path] = self::splitUri(
            $server['REQUEST_URI'] ?? '/',
            $server['SCRIPT_NAME'] ?? ''
        );

        $request = new self($method, $path, $_GET, [], $headers, [], $_COOKIE, $server, $basePath);

        // PHP parses POST bodies before any app code runs. When a body is larger than post_max_size
        // it drops or truncates it and records a startup warning such as "Actual POST length does
        // not match Content-Length, and exceeds 8388608 bytes" (chunked uploads have no
        // Content-Length, so readBody()'s own check can't see their size). Read the warning here,
        // first thing, before anything else (e.g. the rate limiter's @fopen) replaces it.
        $startup = error_get_last();
        $request->phpRejectedBody = $startup !== null
            && ($startup['file'] ?? '') === 'Unknown'
            && preg_match('/\bPOST\b.*\bexceeds\b|\bexceeds\b.*\bPOST\b/i', (string) $startup['message']) === 1;

        return $request;
    }

    /**
     * Reads and parses the body into $this->body / $this->files.
     * Throws HttpException 413 when it is larger than maxBodySize(), 400 when it is malformed.
     */
    public function readBody(): void
    {
        // Refuse oversized bodies before reading them (see maxBodySize()).
        if ((int) ($this->headers['content-length'] ?? 0) > self::maxBodySize()) {
            throw new HttpException(413, 'Request body is too large.');
        }

        // Parse by the method the client really used, not the X-HTTP-Method-Override one: for a real
        // POST, PHP has already parsed the body into $_POST/$_FILES and consumed the input stream,
        // so request_parse_body() would find nothing and the data would be silently lost.
        $realMethod = strtoupper($this->server['REQUEST_METHOD'] ?? $this->method);

        // Oversized chunked POST forms: without this, the app would validate truncated or missing
        // fields and answer a misleading 422 instead of 413.
        //  - PHP left a "... exceeds ..." startup warning (url-encoded forms), or
        //  - a chunked multipart body produced no fields and no files at all: a real multipart body
        //    always has at least one part, so PHP gave up on it (it does this silently when too large).
        $type      = strtolower($this->headers['content-type'] ?? '');
        $chunked   = !isset($this->headers['content-length']) && str_contains(strtolower($this->headers['transfer-encoding'] ?? ''), 'chunked');
        $multipart = str_contains($type, 'multipart/form-data');

        if ($realMethod === 'POST' && ($this->phpRejectedBody || ($chunked && $multipart && $_POST === [] && $_FILES === []))) {
            throw new HttpException(413, 'Request body is too large.');
        }

        [$body, $files] = self::parseBody($realMethod, $this->headers['content-type'] ?? '');

        $this->body  = $body;
        $this->files = self::normalizeFiles($files);
    }

    /** Builds a request by hand — handy in tests. */
    public static function create(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
        array $files = [],
        array $cookies = [],
        array $server = [],
    ): self {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return new self(
            $method,
            (string) parse_url($uri, PHP_URL_PATH),
            $query,
            $body,
            $headers,
            $files,
            $cookies,
            $server + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'],
        );
    }

    /** Ensures one leading slash, no trailing slash and no repeated slashes: '//a/b/' => '/a/b'. */
    public static function normalizePath(string $path): string
    {
        return '/' . trim(preg_replace('#/+#', '/', $path), '/');
    }

    /**
     * Removes the folder the app is served from, so routes work at domain.com/,
     * domain.com/sub/ or domain.com/sub/public/ without changes.
     */
    private static function splitUri(string $requestUri, string $scriptName): array
    {
        $uri       = rawurldecode(parse_url($requestUri, PHP_URL_PATH) ?: '/');
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        foreach ([$scriptDir, preg_replace('#/public$#', '', $scriptDir)] as $basePath) {
            if ($basePath !== '' && ($uri === $basePath || str_starts_with($uri, $basePath . '/'))) {
                return [$basePath, substr($uri, strlen($basePath))];
            }
        }

        return ['', $uri];
    }

    /** Collects HTTP_* entries from $_SERVER as lower-case header names (content-type, authorization, ...). */
    private static function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        // Some Apache setups only expose the Authorization header via REDIRECT_.
        if (!isset($headers['authorization']) && isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return $headers;
    }

    /** @return array{0: array, 1: array} [body, raw $_FILES-style files] */
    private static function parseBody(string $method, string $contentType): array
    {
        $type = strtolower($contentType);

        if (str_contains($type, 'json')) {
            // Read at most one byte more than allowed, so a missing or false Content-Length
            // (e.g. chunked upload) still can't push a huge body into memory.
            $max = self::maxBodySize();
            $raw = file_get_contents('php://input', false, null, 0, $max + 1);
            if ($raw === '' || $raw === false) {
                return [[], []];
            }
            if (strlen($raw) > $max) {
                throw new HttpException(413, 'Request body is too large.');
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new HttpException(400, 'Malformed JSON body.');
            }
            return [$data, []];
        }

        if ($method === 'POST') {
            return [$_POST, $_FILES];
        }

        // PHP only fills $_POST/$_FILES for POST; parse form bodies for the other verbs ourselves.
        $isForm = str_contains($type, 'multipart/form-data')
            || str_contains($type, 'application/x-www-form-urlencoded');

        if ($isForm && in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            try {
                return request_parse_body();
            } catch (RequestParseBodyException) {
                throw new HttpException(400, 'Malformed request body.');
            }
        }

        return [[], []];
    }

    /**
     * Largest request body accepted, in bytes: PHP's post_max_size (e.g. "8M"), which PHP
     * itself only enforces for POST. Using it for every method keeps one limit for all requests.
     * Falls back to 8 MB when post_max_size is 0 (unlimited) or unreadable.
     */
    public static function maxBodySize(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $bytes = (int) $value;

        $bytes *= match (strtolower(substr($value, -1))) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        return $bytes > 0 ? $bytes : 8 * 1024 ** 2;
    }

    /** Turns PHP's $_FILES layout (including name[] arrays) into UploadedFile objects. */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $file) {
            $value = is_array($file['name'])
                ? self::normalizeNestedFiles($file['name'], $file['tmp_name'], $file['error'], $file['size'])
                : self::makeFile($file['tmp_name'], $file['name'], $file['error'], $file['size']);

            if ($value !== null && $value !== []) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /** Handles inputs like docs[] or docs[0][file], where $_FILES nests each property separately. */
    private static function normalizeNestedFiles(array $names, array $tmp, array $errors, array $sizes): array
    {
        $files = [];

        foreach ($names as $key => $name) {
            $value = is_array($name)
                ? self::normalizeNestedFiles($name, $tmp[$key], $errors[$key], $sizes[$key])
                : self::makeFile($tmp[$key], $name, $errors[$key], $sizes[$key]);

            if ($value !== null && $value !== []) {
                $files[$key] = $value;
            }
        }

        return $files;
    }

    /** Wraps one upload; returns null when the field was sent without a file. */
    private static function makeFile(string $tmp, string $name, int $error, int $size): ?UploadedFile
    {
        return $error === UPLOAD_ERR_NO_FILE ? null : new UploadedFile($tmp, $name, $error, $size);
    }

    // ------------------------------------------------------------------ Input

    /** Body + query (body wins) + files. */
    public function all(): array
    {
        return array_replace($this->query, $this->body, $this->files);
    }

    /** One value from body or query; supports dot notation: input('address.city'). */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = array_replace($this->query, $this->body);

        return $key === null ? $data : self::dataGet($data, $key, $default);
    }

    /** One query string value (dot notation supported), or the whole query array when $key is null. */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : self::dataGet($this->query, $key, $default);
    }

    /** Route parameter, e.g. {id} in /users/{id}. */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** only('name', 'email') or only(['name', 'email']) — keys that are present. */
    public function only(string|array ...$keys): array
    {
        $result = [];
        foreach (self::flattenKeys($keys) as $key) {
            if ($this->has($key)) {
                $result[$key] = $this->input($key);
            }
        }
        return $result;
    }

    /** All input except the given keys. */
    public function except(string|array ...$keys): array
    {
        return array_diff_key($this->input(), array_flip(self::flattenKeys($keys)));
    }

    /** True when every given key is present (even if empty). */
    public function has(string|array $keys): bool
    {
        $data = array_replace($this->query, $this->body);
        foreach ((array) $keys as $key) {
            if (self::dataGet($data, $key, $marker = new \stdClass()) === $marker) {
                return false;
            }
        }
        return true;
    }

    /** True when at least one of the keys is present. */
    public function hasAny(string|array $keys): bool
    {
        foreach ((array) $keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    /** True when every given key is present and not empty. */
    public function filled(string|array $keys): bool
    {
        foreach ((array) $keys as $key) {
            $value = $this->input($key);
            if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
                return false;
            }
        }
        return true;
    }

    /** True when the key is not present at all. */
    public function missing(string|array $keys): bool
    {
        return !$this->has($keys);
    }

    /** Input as a trimmed string; $default for arrays/objects. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** Input as an int ('42' => 42); $default when not a valid integer. */
    public function integer(string $key, int $default = 0): int
    {
        $value = filter_var($this->input($key), FILTER_VALIDATE_INT);
        return $value === false ? $default : $value;
    }

    /** Input as a float ('9.5' => 9.5); $default when not numeric. */
    public function float(string $key, float $default = 0.0): float
    {
        $value = filter_var($this->input($key), FILTER_VALIDATE_FLOAT);
        return $value === false ? $default : $value;
    }

    /** "1", "true", "on", "yes" => true. */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $value ?? $default;
    }

    /** Adds or overrides body values (e.g. from middleware). */
    public function merge(array $data): void
    {
        $this->body = array_replace($this->body, $data);
    }

    /**
     * Validates input and returns only the validated fields.
     * Throws ValidationException (422 with field errors) on failure.
     */
    public function validate(array $rules, array $messages = []): array
    {
        return Validator::make($this->all(), $rules, $messages)->validate();
    }

    // ------------------------------------------------------------------ Files & cookies

    /** @return UploadedFile|UploadedFile[]|null */
    public function file(string $key): UploadedFile|array|null
    {
        return self::dataGet($this->files, $key);
    }

    /** True when the field holds a successfully uploaded file. */
    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file instanceof UploadedFile && $file->isValid();
    }

    /** A cookie value sent by the client. */
    public function cookie(string $key, ?string $default = null): ?string
    {
        return $this->cookies[$key] ?? $default;
    }

    // ------------------------------------------------------------------ Headers

    /** A header value; the name is case-insensitive. */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /** True when the client sent this header. */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** Token from "Authorization: Bearer <token>". */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization', '');
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }

    /** The client's User-Agent header. */
    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }

    /** True when the request body was sent as JSON (Content-Type contains "json"). */
    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type', '')), 'json');
    }

    /** True when the client asked for a JSON response (Accept header or AJAX request). */
    public function expectsJson(): bool
    {
        return str_contains(strtolower($this->header('accept', '')), 'json')
            || $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    // ------------------------------------------------------------------ Method, URL, client

    /** HTTP method in upper case, e.g. 'POST'. */
    public function method(): string
    {
        return $this->method;
    }

    /** Case-insensitive method check: isMethod('post'). */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** Path without the base folder and query string, e.g. '/api/v1/users'. */
    public function path(): string
    {
        return $this->path;
    }

    /** Path pattern match: is('api/v1/users/*'). */
    public function is(string ...$patterns): bool
    {
        $path = trim($this->path, '/');
        foreach ($patterns as $pattern) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote(trim($pattern, '/'), '#')) . '$#';
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }

    /** True for HTTPS (also behind a trusted proxy that sets X-Forwarded-Proto). */
    public function isSecure(): bool
    {
        if ($this->fromTrustedProxy() && $this->header('x-forwarded-proto') !== null) {
            return strtolower($this->header('x-forwarded-proto')) === 'https';
        }

        $https = $this->server['HTTPS'] ?? '';
        return ($https !== '' && strtolower($https) !== 'off')
            || (int) ($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    /** 'https' or 'http'. */
    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    /** Host without port. */
    public function host(): string
    {
        $host = $this->header('host') ?? ($this->server['SERVER_NAME'] ?? 'localhost');
        return strtolower(preg_replace('/:\d+$/', '', $host));
    }

    /** URL without query string. */
    public function url(): string
    {
        $host = $this->header('host') ?? $this->host();
        return $this->scheme() . '://' . $host . $this->basePath . ($this->path === '/' ? '' : $this->path);
    }

    /** url() plus the query string. */
    public function fullUrl(): string
    {
        return $this->query === [] ? $this->url() : $this->url() . '?' . http_build_query($this->query);
    }

    /** Real client IP; honours X-Forwarded-For only from proxies in config app.trusted_proxies. */
    public function ip(): string
    {
        $remote = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!$this->fromTrustedProxy() || $this->header('x-forwarded-for') === null) {
            return $remote;
        }

        $hops = array_map('trim', explode(',', $this->header('x-forwarded-for')));

        // Wildcard ['*']: every hop is "trusted", so the walk below would never find the client and
        // would fall back to the proxy's own IP. Use the left-most valid address instead (the
        // original client, as Laravel does). Only use '*' when the server is reachable through the
        // proxy alone; otherwise list the proxy's IPs or CIDR ranges so this branch isn't used.
        if (in_array('*', Config::get('app.trusted_proxies', []), true)) {
            foreach ($hops as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
            return $remote;
        }

        // Walk from the closest hop back; the first untrusted address is the client.
        foreach (array_reverse($hops) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !$this->isTrustedProxy($ip)) {
                return $ip;
            }
        }

        return $remote;
    }

    /** True when the direct connection comes from a proxy listed in app.trusted_proxies. */
    private function fromTrustedProxy(): bool
    {
        return $this->isTrustedProxy($this->server['REMOTE_ADDR'] ?? '');
    }

    /**
     * True when $ip is in app.trusted_proxies. Entries can be single IPs ('10.0.0.1'),
     * CIDR ranges ('173.245.48.0/20', '2400:cb00::/32', e.g. Cloudflare's published ranges)
     * or '*'. Prefer explicit ranges over '*': with '*', anyone who can reach the server
     * directly can fake X-Forwarded-For.
     */
    private function isTrustedProxy(string $ip): bool
    {
        foreach (Config::get('app.trusted_proxies', []) as $entry) {
            if ($entry === '*' || $entry === $ip || (str_contains($entry, '/') && self::ipInRange($ip, $entry))) {
                return true;
            }
        }
        return false;
    }

    /** True when $ip falls inside the CIDR range $cidr (IPv4 or IPv6; mixed families never match). */
    private static function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2) + [1 => ''];

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        // preg_match rather than ctype_digit(): the ctype extension isn't guaranteed on every PHP build.
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || !preg_match('/^\d+$/', $bits)) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits > strlen($ipBin) * 8) {
            return false;
        }

        // Compare whole bytes first, then the remaining bits of the last partial byte.
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    // ------------------------------------------------------------------ Per-request state

    /** The authenticated user, set by auth middleware via setUser(). */
    public function user(): mixed
    {
        return $this->attributes['user'] ?? null;
    }

    /** Stores the authenticated user (called by AuthMiddleware). */
    public function setUser(mixed $user): void
    {
        $this->attributes['user'] = $user;
    }

    /** Arbitrary data shared between middleware and controllers. */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Stores a value for later middleware/controllers in this request. */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /** @internal Called by the Router after matching a route. */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    // ------------------------------------------------------------------ Magic access

    /** $request->email => route param, then body, then query, then file. */
    public function __get(string $key): mixed
    {
        return $this->params[$key] ?? self::dataGet($this->all(), $key);
    }

    /** Lets isset($request->field) work with the magic property shortcut. */
    public function __isset(string $key): bool
    {
        return $this->__get($key) !== null;
    }

    // ------------------------------------------------------------------ Helpers

    /** Reads a nested array value with dot notation: dataGet($data, 'address.city'). */
    private static function dataGet(array $data, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /** Accepts only('a', 'b') and only(['a', 'b']) alike. */
    private static function flattenKeys(array $keys): array
    {
        $flat = [];
        array_walk_recursive($keys, function ($key) use (&$flat) {
            $flat[] = $key;
        });
        return $flat;
    }
}
