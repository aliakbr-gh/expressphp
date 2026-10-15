<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use Core\JWT;
use Core\Request;
use Core\Response;

final class AuthController
{
    private const DUMMY_HASH = '$2y$12$DBeQl5U1JkHMIB1hFWqQuujxas4A6FOj81FT9HWSc28GJuIlGP2T.';

    public function register(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'username' => 'required|string|min:3|max:50|alpha_dash|unique:users,username',
            'password' => 'required|string|min:8|max:72',
        ]);

        $id   = User::create(trim($data['name']), $data['username'], password_hash($data['password'], PASSWORD_DEFAULT));
        $user = User::findById($id);

        return $response->success($this->tokenPayload($user), 'Registered successfully.', 201);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::findByUsernameWithPassword($data['username']);

        if (!password_verify($data['password'], $user['password'] ?? self::DUMMY_HASH) || $user === null) {
            return $response->error('Invalid username or password.', 401);
        }

        unset($user['password']);

        return $response->success($this->tokenPayload($user), 'Logged in successfully.');
    }

    public function me(Request $request, Response $response): Response
    {
        dd($request->user());
        return $response->success($request->user(), 'Authenticated user.');
    }

    private function tokenPayload(array $user): array
    {
        return [
            'user'       => $user,
            'token'      => JWT::encode(['sub' => $user['id']]),
            // 'token_type' => 'Bearer',
            // 'expires_in' => JWT::ttl(),
        ];
    }
}
