<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Minimal JSON Web Token (HS256) implementation.
 *
 *   $token  = Jwt::encode(['sub' => $user['id']]);   // adds iat + exp from config
 *   $claims = Jwt::decode($token);                 // throws JwtException if invalid or expired
 *
 * Settings: jwt.secret and jwt.ttl in config/config.php.
 */
final class Jwt
{
    private const ALGORITHM = 'HS256';
    private const LEEWAY    = 30; // seconds of allowed clock drift between servers

    /** Creates a signed token. iat (issued at) and exp (expiry) are added unless given in $claims. */
    public static function encode(array $claims): string
    {
        $now     = time();
        $payload = $claims + ['iat' => $now, 'exp' => $now + self::ttl()];
        $header  = ['typ' => 'JWT', 'alg' => self::ALGORITHM];

        $segments = self::base64UrlEncode(self::json($header)) . '.' . self::base64UrlEncode(self::json($payload));

        return $segments . '.' . self::base64UrlEncode(self::sign($segments));
    }

    /** Returns the payload claims, or throws JwtException. */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Invalid token.');
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode(self::base64UrlDecode($header64), true);
        // Pin the algorithm: never trust the token's own "alg" (blocks "alg: none" attacks).
        if (!is_array($header) || ($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new JwtException('Invalid token.');
        }

        if (!hash_equals(self::sign("{$header64}.{$payload64}"), self::base64UrlDecode($signature64))) {
            throw new JwtException('Invalid token.');
        }

        $payload = json_decode(self::base64UrlDecode($payload64), true);
        if (!is_array($payload)) {
            throw new JwtException('Invalid token.');
        }

        $now = time();
        if (!isset($payload['exp']) || !is_int($payload['exp']) || $payload['exp'] < $now - self::LEEWAY) {
            throw new JwtException('Token has expired.');
        }
        if (isset($payload['nbf']) && $payload['nbf'] > $now + self::LEEWAY) {
            throw new JwtException('Invalid token.');
        }

        return $payload;
    }

    /** Token lifetime in seconds. */
    public static function ttl(): int
    {
        return (int) Config::get('jwt.ttl', 86400);
    }

    /** HMAC-SHA256 signature of "header.payload" using jwt.secret (raw binary). */
    private static function sign(string $data): string
    {
        $secret = (string) Config::get('jwt.secret', '');
        if (strlen($secret) < 32) {
            throw new RuntimeException('jwt.secret in config/config.php must be at least 32 characters.');
        }

        return hash_hmac('sha256', $data, $secret, true);
    }

    /** Compact JSON encoding used for the header and payload segments. */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** URL-safe base64 without padding, as required by the JWT spec. */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Reverses base64UrlEncode(); returns '' for invalid input. */
    private static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
