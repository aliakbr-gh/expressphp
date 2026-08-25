<?php

declare(strict_types=1);

namespace App\Middlewares;

use ExpressPHP\Auth\JWT;
use ExpressPHP\Auth\JWTException;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use App\Models\User;

final class AuthMiddleware
{
    public function __construct(private readonly User $users = new User())
    {
    }

    public function __invoke(Request $request, Response $response): ?Response
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return $this->unauthorized($response);
        }

        try {
            $claims = JWT::decode($token);
        } catch (JWTException) {
            return $this->unauthorized($response);
        }

        $user = $this->users->findDetailed((string)$claims['sub']);
        if (
            $user === null
            || !(bool)($user['is_active'] ?? false)
            || !isset($claims['sv'])
            || (int)$claims['sv'] !== (int)$user['session_version']
        ) {
            return $this->unauthorized($response);
        }

        $request->setUser($user);
        return null;
    }

    private function unauthorized(Response $response): Response
    {
        return $response
            ->header('WWW-Authenticate', 'Bearer realm="ExpressPHP API"')
            ->error(['Invalid or expired token.'], 401);
    }
}
