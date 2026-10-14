<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Models\User;
use Core\JWT;
use Core\JWTException;
use Core\MiddlewareInterface;
use Core\Request;
use Core\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return $this->unauthorized('Invalid or expired token');
        }

        try {
            $claims = JWT::decode($token);
        } catch (JWTException $e) {
            return $this->unauthorized($e->getMessage());
        }

        $user = is_int($claims['sub'] ?? null) ? User::findById($claims['sub']) : null;
        if ($user === null) {
            return $this->unauthorized('Invalid or expired token');
        }

        $request->setUser($user);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return (new Response())->error($message, 401)->header('WWW-Authenticate', 'Bearer');
    }
}
