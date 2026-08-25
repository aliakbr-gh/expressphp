<?php

declare(strict_types=1);

namespace ExpressPHP\Auth;

use InvalidArgumentException;

final class AccessToken
{
    public static function forUser(array $user, array $claims = []): array
    {
        if (!isset($user['id'], $user['username'], $user['session_version'])) {
            throw new InvalidArgumentException(
                'A user id, username, and session version are required to issue an access token.'
            );
        }

        return [
            'user' => $user,
            'token' => JWT::encode([
                ...$claims,
                'sub' => (string)$user['id'],
                'username' => (string)$user['username'],
                'sv' => (int)$user['session_version'],
            ]),
            'token_type' => 'Bearer',
            'expires_in' => JWT::ttl(),
        ];
    }
}
