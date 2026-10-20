<?php

declare(strict_types=1);

/**
 * Front controller: every API request is rewritten here (see /.htaccess).
 */

require __DIR__ . '/../core/bootstrap.php';

use Core\Database\Database;
use Core\Http\Cors;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Router;
use Core\Support\Config;
use Core\Support\Log;
use Core\Support\RateLimiter;
use Core\Validation\ValidationException;

// Headers, path and client IP only; never throws, so every request below can be rate-limited
// and logged, including ones whose body later turns out to be malformed (400) or too large (413).
$request = Request::fromGlobals();

try {
    if (Cors::isPreflight($request)) {
        // A browser's OPTIONS check before a cross-origin request: answered here, not routed (which
        // would be a 405). Not rate-limited: it's cheap, and the real request after it is counted.
        $response = Cors::preflight($request);
    } else {
        // Global limit for every request, per client IP (see rate_limit in config/config.php).
        // Checked before the body is read, so oversized or malformed bodies count too.
        $wait = RateLimiter::hit($request->ip());
        if ($wait > 0) {
            throw new HttpException(429, "Too many requests. Try again in {$wait} seconds.", null, ['Retry-After' => (string) $wait]);
        }

        $request->readBody();

        $router = new Router();
        require BASE_PATH . '/routes/api.php';

        $response = $router->dispatch($request);
    }
} catch (HttpException $e) {
    $response = (new Response())->error($e->getMessage(), $e->status(), $e->errors())
        ->withHeaders($e->headers());
} catch (Throwable $e) {
    $duplicate = Database::duplicateKeyColumn($e);

    if ($duplicate !== null && $duplicate !== '') {
        // Two requests passed a "unique" rule at the same moment and the database's unique index
        // rejected the second insert: answer like the validator would (422), not with a server error.
        // Logged once, by index name only (never the duplicated value).
        Log::warning('Duplicate key on unique index ' . Database::duplicateKeyIndex($e));

        $message = 'The ' . str_replace('_', ' ', $duplicate) . ' has already been taken.';
        $error   = new ValidationException([$duplicate => [$message]]);

        $response = (new Response())->error($error->getMessage(), $error->status(), $error->errors());
    } else {
        // Any other error, including duplicates on indexes that don't follow <table>_<column>_unique
        // (internal indexes): a real server-side problem, so log it once and answer 500.
        // Log::exception() masks the duplicated value of duplicate-key errors.
        Log::exception($e);

        $response = Config::get('app.debug')
            ? (new Response())->error($e->getMessage(), 500, [
                'exception' => $e::class,
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'trace'     => explode("\n", $e->getTraceAsString()),
            ])
            : (new Response())->error('Internal server error.', 500);
    }
}

// CORS headers on every answer, errors included, so browser apps can read 401/422/429 bodies too.
$response = Cors::apply($request, $response);

try {
    $response->send();
} catch (Throwable $e) {
    // send() encodes the body before sending anything, so a failure here (e.g. INF/NaN in the data)
    // can still be answered with a clean 500 instead of a broken or empty response.
    Log::exception($e);
    $response = (new Response())->error('Internal server error.', 500);
    if (!headers_sent()) {
        $response->send();
    }
}

// One line per request, including 400/413/429 answers.
Log::request($request, $response->status, $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
