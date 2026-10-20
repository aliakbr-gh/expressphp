<?php

declare(strict_types=1);

namespace Core\Validation;

use Core\Http\HttpException;

/**
 * Thrown when validation fails; rendered as 422 with per-field errors:
 *   { "success": false, "message": "The email field is required.", "errors": { "email": ["..."] } }
 */
final class ValidationException extends HttpException
{
    /** @param array<string, string[]> $errors Field => list of messages; the first message becomes the response message. */
    public function __construct(array $errors)
    {
        $first = reset($errors)[0] ?? 'The given data was invalid.';
        parent::__construct(422, $first, $errors);
    }
}
