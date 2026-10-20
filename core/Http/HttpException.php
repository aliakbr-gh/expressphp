<?php

declare(strict_types=1);

namespace Core\Http;

use RuntimeException;

/**
 * Throw anywhere to return an HTTP error response, e.g. throw new HttpException(404, 'User not found.').
 */
class HttpException extends RuntimeException
{
    /**
     * @param int $status HTTP status code to respond with
     * @param mixed $errors Optional details placed in the "errors" key of the response
     * @param array $headers Extra response headers (e.g. Allow for 405)
     */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly mixed $errors = null,
        private readonly array $headers = [],
    ) {
        parent::__construct($message, $status);
    }

    /** HTTP status code for the response. */
    public function status(): int
    {
        return $this->status;
    }

    /** Details for the "errors" key of the response, or null. */
    public function errors(): mixed
    {
        return $this->errors;
    }

    /** Extra headers to send with the error response. */
    public function headers(): array
    {
        return $this->headers;
    }
}
