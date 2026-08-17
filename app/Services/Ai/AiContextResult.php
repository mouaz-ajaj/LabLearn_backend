<?php

namespace App\Services\Ai;

use App\Enums\AiContextStatus;

/**
 * ai_context is always present and always has this same shape (status + content),
 * regardless of whether Gemini actually answered or a deterministic fallback was
 * used - the frontend never needs to branch on which one produced it.
 */
final readonly class AiContextResult
{
    /** @param array<string, mixed> $content */
    public function __construct(
        public AiContextStatus $status,
        public array $content,
    ) {}
}
