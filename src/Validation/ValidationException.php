<?php

declare(strict_types=1);

namespace ExpressPHP\Validation;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The request contains invalid data.');
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
