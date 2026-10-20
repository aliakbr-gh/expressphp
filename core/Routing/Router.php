<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use RuntimeException;

/**
 * Minimal router with route params ({id}), groups (prefix + middleware) and 404/405 handling.
 *
 *   $router->get('/users/{id}', [UserController::class, 'show']);
 *   $router->group('v1', function (Router $r) { ... });
 *   $router->group('admin', function (Router $r) { ... }, [AuthMiddleware::class]);
 */
final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    /** Registers a GET route (HEAD requests are answered by it too). */
    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add(['GET', 'HEAD'], $path, $handler, $middleware);
    }

    /** Registers a POST route. */
    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add(['POST'], $path, $handler, $middleware);
    }

    /** Registers a PUT route. */
    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add(['PUT'], $path, $handler, $middleware);
    }

    /** Registers a PATCH route. */
    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add(['PATCH'], $path, $handler, $middleware);
    }

    /** Registers a DELETE route. */
    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add(['DELETE'], $path, $handler, $middleware);
    }

    /**
     * Routes registered inside $callback get $prefix added to their path and $middleware
     * applied, on top of any outer groups:
     *   $router->group('files', function (Router $r) { ... }, [AuthMiddleware::class]);
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $callback($this);
        array_pop($this->groupStack);
    }

    /** Stores a route, applying the prefixes and middleware of the groups currently open. */
    private function add(array $methods, string $path, callable|array $handler, array $middleware): void
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= '/' . trim($group['prefix'] ?? '', '/');
            $groupMiddleware = array_merge($groupMiddleware, $group['middleware'] ?? []);
        }

        $path = Request::normalizePath($prefix . '/' . $path);

        $this->routes[] = [
            'methods'    => $methods,
            'path'       => $path,
            'pattern'    => $this->compile($path),
            'handler'    => $handler,
            'middleware' => array_merge($groupMiddleware, $middleware),
        ];
    }

    /** Turns '/users/{id}' into a regex with a named group for each {param}. */
    private function compile(string $path): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    /** Finds the route for the request and runs it. Throws HttpException 404/405 when none matches. */
    public function dispatch(Request $request): Response
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->path, $matches)) {
                continue;
            }

            if (!in_array($request->method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }

            $request->setParams(array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));

            return $this->runPipeline($request, $route);
        }

        if ($allowed) {
            throw new HttpException(405, 'Method not allowed.', null, [
                'Allow' => implode(', ', array_unique($allowed)),
            ]);
        }

        throw new HttpException(404, 'Route not found.');
    }

    /** Wraps the controller in the route's middleware (first listed runs first) and runs it. */
    private function runPipeline(Request $request, array $route): Response
    {
        $core = fn(Request $req): Response => $this->callHandler($route['handler'], $req);

        $pipeline = array_reduce(
            array_reverse($route['middleware']),
            static function (callable $next, string $middlewareClass): callable {
                return static function (Request $req) use ($next, $middlewareClass): Response {
                    $middleware = new $middlewareClass();
                    if (!$middleware instanceof MiddlewareInterface) {
                        throw new RuntimeException("{$middlewareClass} must implement MiddlewareInterface.");
                    }
                    return $middleware->handle($req, $next);
                };
            },
            $core
        );

        return $pipeline($request);
    }

    /** Calls the controller method or closure with ($request, $response). */
    private function callHandler(callable|array $handler, Request $request): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            [$class, $method] = $handler;
            $handler = [new $class(), $method];
        }

        $response = $handler($request, new Response());

        // A plain array is wrapped in the standard success envelope.
        if (is_array($response)) {
            return (new Response())->success($response);
        }

        if (!$response instanceof Response) {
            throw new RuntimeException('Route handlers must return a Response or an array.');
        }

        return $response;
    }
}
