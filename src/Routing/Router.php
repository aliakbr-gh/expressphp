<?php

declare(strict_types=1);

namespace ExpressPHP\Routing;

use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Core\Debugger;
use RuntimeException;

final class Router
{
    private array $routes = [];
    private array $prefixStack = [];
    private array $middlewareStack = [];

    public function __construct(private readonly array $middlewareAliases = [])
    {
    }

    public function get(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('GET', $path, $action, $middleware);
    }

    public function post(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('POST', $path, $action, $middleware);
    }

    public function put(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('PUT', $path, $action, $middleware);
    }

    public function patch(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('PATCH', $path, $action, $middleware);
    }

    public function delete(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $action, $middleware);
    }

    public function group(
        string         $prefix,
        callable|array $middlewareOrCallback,
        ?callable      $callback = null,
    ): self {
        if ($callback === null) {
            if (!is_callable($middlewareOrCallback)) {
                throw new RuntimeException('A route group callback is required.');
            }
            $callback = $middlewareOrCallback;
            $middleware = [];
        } else {
            $middleware = is_array($middlewareOrCallback) ? $middlewareOrCallback : [$middlewareOrCallback];
            foreach ($middleware as $item) {
                if (!is_string($item) && !is_callable($item)) {
                    throw new RuntimeException('Group middleware must be an alias or callable.');
                }
            }
        }

        $this->prefixStack[] = $this->normalizePath($prefix);
        $this->middlewareStack[] = $middleware;

        try {
            $callback($this);
        } finally {
            array_pop($this->prefixStack);
            array_pop($this->middlewareStack);
        }

        return $this;
    }

    public function add(string $method, string $path, callable|array $action, array $middleware = []): self
    {
        if (!$this->validAction($action)) {
            throw new RuntimeException('A route action must be callable or [Controller::class, method].');
        }
        foreach ($middleware as $item) {
            if (!is_string($item) && !is_callable($item)) {
                throw new RuntimeException('Route middleware must be an alias or callable.');
            }
        }

        $path = $this->joinPaths(...[...$this->prefixStack, $path]);
        $groupMiddleware = $this->middlewareStack === [] ? [] : array_merge(...$this->middlewareStack);
        $this->routes[] = new Route(strtoupper($method), $path, $action, [...$groupMiddleware, ...$middleware]);
        return $this;
    }

    public function dispatch(Request $request, Response $response): Response
    {
        foreach ($this->routes as $route) {
            $params = $route->match($request->method(), $request->path());

            if ($params === null) {
                continue;
            }

            $request = $request->withParams($params);
            Debugger::setRequest($request);
            foreach ($route->middleware as $definition) {
                [$middleware, $parameters] = $this->resolveMiddleware($definition);
                $result = $middleware($request, $response, ...$parameters);
                if ($result instanceof Response) {
                    return $result;
                }
            }

            $action = $this->resolveAction($route->action);
            $result = $action($request, $response);
            return $result instanceof Response ? $result : $response;
        }

        return $response->error('Route not found', 404);
    }

    private function validAction(mixed $action): bool
    {
        return is_callable($action)
            || (is_array($action) && count($action) === 2 && is_string($action[0]) && is_string($action[1]));
    }

    private function resolveAction(mixed $action): callable
    {
        if (is_array($action) && is_string($action[0])) {
            $class = $action[0];
            $method = $action[1];
            if (!class_exists($class)) {
                throw new RuntimeException("Controller [{$class}] was not found.");
            }
            $controller = new $class();
            if (!is_callable([$controller, $method])) {
                throw new RuntimeException("Controller action [{$class}::{$method}] is not callable.");
            }
            return [$controller, $method];
        }
        if (!is_callable($action)) {
            throw new RuntimeException('Route action is not callable.');
        }
        return $action;
    }

    private function resolveMiddleware(mixed $definition): array
    {
        $parameters = [];
        if (is_string($definition)) {
            [$alias, $parameterText] = array_pad(explode(':', $definition, 2), 2, '');
            if (!array_key_exists($alias, $this->middlewareAliases)) {
                throw new RuntimeException("Middleware alias [{$alias}] is not configured.");
            }
            $definition = $this->middlewareAliases[$alias];
            $parameters = $parameterText === '' ? [] : array_map('trim', explode(',', $parameterText));
        }

        if (is_string($definition) && class_exists($definition)) {
            $definition = new $definition();
        }
        if (!is_callable($definition)) {
            throw new RuntimeException('Resolved middleware is not callable.');
        }
        return [$definition, $parameters];
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    private function joinPaths(string ...$paths): string
    {
        $segments = [];
        foreach ($paths as $path) {
            $path = trim($path, '/');
            if ($path !== '') {
                $segments[] = $path;
            }
        }
        return $this->normalizePath(implode('/', $segments));
    }
}
