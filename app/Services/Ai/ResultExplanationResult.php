<?php

namespace App\Services\Ai;

use App\Enums\AiContextStatus;
use App\Enums\AiExplanationSource;

/**
 * The outward `status` (AVAILABLE|FALLBACK) mirrors Phase 4C's AiContextResult shape
 * exactly, so the frontend contract stays consistent across both AI features. `source`
 * is the finer-grained, internal-only detail (CACHED|GENERATED|FALLBACK) useful for
 * logging/tests/observability - never required reading by the UI.
 */
final readonly class ResultExplanationResult
{
    /** @param array<string, mixed> $content */
    public function __construct(
        public AiContextStatus $status,
        public AiExplanationSource $source,
        public array $content,
    ) {}
}
