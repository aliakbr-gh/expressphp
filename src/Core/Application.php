<?php

declare(strict_types=1);

namespace ExpressPHP\Core;

use ExpressPHP\Auth\JWT;
use ExpressPHP\Auth\Password;
use ExpressPHP\Database\Database;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Http\CORS;
use ExpressPHP\Http\HttpException;
use ExpressPHP\Logging\RequestLogger;
use ExpressPHP\Mail\SMTPMailer;
use ExpressPHP\RateLimit\RateLimitDecision;
use ExpressPHP\RateLimit\RateLimiter;
use ExpressPHP\Routing\Router;
use ExpressPHP\Storage\FileUploader;
use ExpressPHP\Validation\ValidationException;
use PDOException;
use Throwable;

final class Application
{
    private Router $router;
    private RateLimiter $rateLimiter;

    public function __construct(private readonly array $config = [])
    {
        Database::configure($config['databases'] ?? []);
        JWT::configure($config['jwt'] ?? []);
        Password::configure($config['password'] ?? []);
        SMTPMailer::configure($config['mail'] ?? []);
        FileUploader::configure($config['uploads'] ?? []);
        Debugger::configure([
            ...($config['debug_dump'] ?? []),
            'timezone' => $config['timezone'] ?? 'UTC',
        ]);
        RequestLogger::configure([
            ...($config['logging'] ?? []),
            'timezone' => $config['timezone'] ?? 'UTC',
        ]);
        $this->rateLimiter = new RateLimiter($config['rate_limiter'] ?? []);
        $this->router = new Router($config['middleware'] ?? []);
    }

    public function get(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->get($path, $action, $middleware);
        return $this;
    }

    public function post(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->post($path, $action, $middleware);
        return $this;
    }

    public function put(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->put($path, $action, $middleware);
        return $this;
    }

    public function patch(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->patch($path, $action, $middleware);
        return $this;
    }

    public function delete(string $path, callable|array $action, array $middleware = []): self
    {
        $this->router->delete($path, $action, $middleware);
        return $this;
    }

    public function group(
        string         $prefix,
        callable|array $handlersOrCallback,
        ?callable      $callback = null,
    ): self {
        $this->router->group($prefix, $handlersOrCallback, $callback);
        return $this;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function dispatch(Request $request): Response
    {
        Debugger::setRequest($request);
        $cors = new CORS($this->config['cors'] ?? []);
        $decision = null;

        try {
            if ($request->method() === 'OPTIONS') {
                return $cors->apply($request, $this->securityHeaders((new Response())->noContent(), $request));
            }

            $decision = $this->rateLimiter->check($request);
            if (!$decision->allowed) {
                return $cors->apply(
                    $request,
                    $this->securityHeaders($this->rateLimitResponse($decision), $request),
                );
            }

            $response = $this->router->dispatch($request, new Response());
            return $cors->apply(
                $request,
                $this->securityHeaders($this->rateLimitHeaders($response, $decision), $request),
            );
        } catch (ValidationException $exception) {
            $response = (new Response())->error($exception->errors(), 422);
        } catch (HttpException $exception) {
            $response = (new Response())->error($exception->getMessage(), $exception->statusCode());
        } catch (PDOException $exception) {
            $response = $this->databaseErrorResponse($exception);
        } catch (Throwable $exception) {
            $debug = (bool)($this->config['debug'] ?? false);

            $message = $debug
                ? 'Internal Server Error: ' . $exception->getMessage()
                : 'Internal Server Error';

            $response = (new Response())->error($message, 500);
        }

        if ($decision instanceof RateLimitDecision) {
            $response = $this->rateLimitHeaders($response, $decision);
        }

        return $cors->apply($request, $this->securityHeaders($response, $request));
    }

    public function run(?Request $request = null): never
    {
        $request ??= Request::capture($this->config['trusted_proxies'] ?? []);
        $response = $this->dispatch($request);
        $response->emit();

        // Finish the client response before touching the log file when PHP-FPM supports it.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        RequestLogger::write($request, $response);
        exit;
    }

    private function rateLimitResponse(RateLimitDecision $decision): Response
    {
        if ($decision->blocked && $decision->retryAfter === 0) {
            $response = (new Response())->status(403)->json([
                'success' => false,
                'message' => 'This IP address has been blocked',
                'data' => null,
            ]);
        } else {
            $response = (new Response())->status(429)->json([
                'success' => false,
                'message' => $decision->blocked ? 'This IP address is temporarily blocked' : 'Too many requests',
                'data' => [
                    'retry_after' => $decision->retryAfter,
                    'violations' => $decision->violations,
                    'max_violations' => $decision->maxViolations,
                ],
            ])->header('Retry-After', (string)$decision->retryAfter);
        }

        return $this->rateLimitHeaders($response, $decision);
    }

    private function databaseErrorResponse(PDOException $exception): Response
    {
        if ((string)$exception->getCode() !== '23000') {
            return (new Response())->error('Internal Server Error', 500);
        }

        $driverCode = (int)($exception->errorInfo[1] ?? 0);

        return match ($driverCode) {
            1062 => (new Response())->error('A record with the same unique value already exists', 409),
            1451 => (new Response())->error('This record cannot be deleted because it is currently in use', 409),
            1452 => (new Response())->error('The selected related record does not exist', 422),
            default => (new Response())->error('A database constraint prevented this operation', 409),
        };
    }

    private function rateLimitHeaders(Response $response, RateLimitDecision $decision): Response
    {
        return $response
            ->header('X-RateLimit-Limit', (string)$decision->limit)
            ->header('X-RateLimit-Remaining', (string)$decision->remaining)
            ->header('X-RateLimit-Reset', (string)$decision->resetAt);
    }

    private function securityHeaders(Response $response, Request $request): Response
    {
        $response
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'DENY')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->secure()) {
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
