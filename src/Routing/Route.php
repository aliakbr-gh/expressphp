<?php

declare(strict_types=1);

namespace ExpressPHP\Routing;

final class Route
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed  $action,
        public readonly array  $middleware = [],
    ) {
    }

    public function match(string $method, string $path): ?array
    {
        if ($this->method !== $method) {
            return null;
        }

        $parameterNames = [];
        $quoted = preg_quote($this->path, '#');
        $pattern = preg_replace_callback('/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/', static function (array $match) use (&$parameterNames): string {
            $parameterNames[] = $match[1];
            return '([^/]+)';
        }, $quoted);

        if ($pattern === null || preg_match('#^' . $pattern . '$#', $path, $matches) !== 1) {
            return null;
        }

        array_shift($matches);
        return array_combine($parameterNames, array_map('urldecode', $matches)) ?: [];
    }
}
