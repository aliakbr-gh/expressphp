<?php

declare(strict_types=1);

namespace Core;

/**
 * CORS: lets browser apps on other origins (e.g. a React app on another domain) call the API.
 *
 * Before a cross-origin request with an Authorization header or a JSON body, the browser first sends
 * an OPTIONS "preflight" request. public/index.php answers it here (204) instead of routing it, and
 * adds the CORS headers to every other response, errors included, so the browser can read them.
 *
 * Settings: cors in config/config.php. Requests without an Origin header (curl, Postman, servers)
 * are not affected.
 */
final class CORS
{
    /** True for a browser's CORS preflight: OPTIONS with Origin and Access-Control-Request-Method. */
    public static function isPreflight(Request $request): bool
    {
        return $request->method === 'OPTIONS'
            && $request->hasHeader('origin')
            && $request->hasHeader('access-control-request-method');
    }

    /** The answer to a preflight: 204 with the CORS headers (none when the origin isn't allowed). */
    public static function preflight(Request $request): Response
    {
        return self::apply($request, (new Response())->noContent());
    }

    /** Adds the CORS headers for $request's origin to $response. */
    public static function apply(Request $request, Response $response): Response
    {
        $allowed     = (array) Config::get('cors.allowed_origins', []);
        $credentials = (bool) Config::get('cors.allow_credentials', false);
        $any         = in_array('*', $allowed, true);

        // The answer depends on the Origin header unless it's always "*": tell caches so.
        if (!$any || $credentials) {
            $response->header('Vary', 'Origin');
        }

        $origin = $request->header('origin');
        if ($origin === null || !($any || self::listed($origin, $allowed))) {
            return $response;
        }

        // Browsers reject "*" together with credentials, so the origin is echoed back then.
        $response->header('Access-Control-Allow-Origin', $any && !$credentials ? '*' : $origin);
        if ($credentials) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }

        if (self::isPreflight($request)) {
            $response->withHeaders([
                'Access-Control-Allow-Methods' => implode(', ', (array) Config::get('cors.allowed_methods', [])),
                'Access-Control-Allow-Headers' => implode(', ', (array) Config::get('cors.allowed_headers', [])),
                'Access-Control-Max-Age'       => (string) (int) Config::get('cors.max_age', 0),
            ]);
        } elseif ($exposed = (array) Config::get('cors.exposed_headers', [])) {
            $response->header('Access-Control-Expose-Headers', implode(', ', $exposed));
        }

        return $response;
    }

    /** Exact match against the list (scheme, host and port), ignoring letter case and a trailing slash. */
    private static function listed(string $origin, array $allowed): bool
    {
        $origin = strtolower($origin);
        foreach ($allowed as $entry) {
            if (strtolower(rtrim((string) $entry, '/')) === $origin) {
                return true;
            }
        }
        return false;
    }
}
