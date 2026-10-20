<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Http\Request;
use Core\Http\Response;

/**
 * Middleware wraps a route handler. Call $next($request) to continue,
 * or return a Response directly to short-circuit (e.g. 401 Unauthorized).
 */
interface MiddlewareInterface
{
    /**
     * Return $next($request) to continue to the next middleware/controller,
     * or return a Response to stop the request here.
     */
    public function handle(Request $request, callable $next): Response;
}
