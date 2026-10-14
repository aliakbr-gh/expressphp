<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/** Thrown by JWT::decode() for malformed, tampered or expired tokens. */
final class JWTException extends RuntimeException
{
}
