<?php

declare(strict_types=1);

namespace ExpressPHP\RateLimit;

final class RateLimitDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $blocked,
        public readonly int  $limit,
        public readonly int  $remaining,
        public readonly int  $resetAt,
        public readonly int  $retryAfter = 0,
        public readonly int  $violations = 0,
        public readonly int  $maxViolations = 0,
    ) {
    }
}
