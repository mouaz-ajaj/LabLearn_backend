<?php

namespace App\Services\Ocr;

use RuntimeException;
use Throwable;

class OcrException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $safeMessage,
        public readonly bool $retryable,
        public readonly ?int $serviceStatus = null,
        public readonly ?string $serviceErrorCode = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return array_filter([
            'error_code' => $this->errorCode,
            'retryable' => $this->retryable,
            'ocr_service_status' => $this->serviceStatus,
            'ocr_service_error_code' => $this->serviceErrorCode,
            'ocr_request_id' => $this->requestId,
        ], fn (mixed $value): bool => $value !== null);
    }
}
