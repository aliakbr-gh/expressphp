<?php

declare(strict_types=1);

namespace App\Controllers;

use ExpressPHP\Auth\AccessToken;
use ExpressPHP\Auth\Password;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use App\Models\User;

final class AuthController
{
    public function __construct(private readonly User $users = new User())
    {
    }

    public function register(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'username' => 'required|string|min:3|max:50|unique:users,username',
            'password' => 'required|string|min:8|max:72',
        ]);

        $data['password'] = Password::hash($data['password']);
        $data['role_id'] = $this->users->registrationRoleId();
        $data['is_active'] = true;
        $data['session_version'] = 1;
        $created = $this->users->create($data);
        $user = $this->users->findDetailed($created['id']);
        if ($user === null) {
            return $response->error('Registration failed', 500);
        }

        return $response->success($user, 'Registration successful', 201);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = $this->users->authenticate($data['username'], $data['password']);
        if ($user === null) {
            return $response->error(['Invalid username or password.'], 401);
        }

        return $response->success(AccessToken::forUser($user), 'Login successful');
    }

    public function me(Request $request, Response $response): Response
    {
        return $response->success($request->user(), 'Authenticated user loaded');
    }

    public function refresh(Request $request, Response $response): Response
    {
        $user = $request->user();
        $this->users->incrementSessionVersion($user['id']);
        $user = $this->users->findDetailed($user['id']);

        return $response->success(AccessToken::forUser($user ?? []), 'Token refreshed');
    }

    public function logout(Request $request, Response $response): Response
    {
        $user = $request->user();
        $this->users->incrementSessionVersion($user['id']);
        return $response->success(null, 'Logout successful');
    }
}
