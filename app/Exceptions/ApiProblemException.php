<?php

namespace App\Exceptions;

use RuntimeException;

final class ApiProblemException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $fields
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
