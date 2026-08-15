<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ApiException extends RuntimeException implements ShouldntReport
{
    /** @param array<string, mixed> $errors */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }
}
