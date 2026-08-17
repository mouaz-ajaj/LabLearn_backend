<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class GeminiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable,
        public readonly ?int $statusCode = null,
        public readonly ?string $serviceCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
