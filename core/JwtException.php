<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/** Thrown by Jwt::decode() for malformed, tampered or expired tokens. */
final class JwtException extends RuntimeException
{
}
