<?php

declare(strict_types=1);

namespace App\Middlewares;

use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class RoleMiddleware
{
    public function __invoke(Request $request, Response $response, string ...$allowed): ?Response
    {
        $user = $request->user();
        if ($user === null) {
            return $response->error('Unauthenticated', 401);
        }

        return in_array((string)($user['role_slug'] ?? ''), $allowed, true)
            ? null
            : $response->error('Your role cannot perform this action', 403);
    }
}
