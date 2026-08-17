<?php

namespace App\Contracts;

use App\Models\User;
use App\Services\Ai\AiContextResult;

/**
 * Swappable AI-provider boundary for comparison contextualization. Gemini is the only
 * implementation today (GeminiContextualizer), but Comparison Core never depends on it
 * directly - only on this contract - so a future provider change never touches the
 * deterministic comparison logic. Implementations own building the minimal payload,
 * calling the provider, validating its response, and falling back deterministically -
 * this method must never throw; AI failure can never break a comparison request.
 */
interface AiContextualizer
{
    /** @param  array<string, mixed>  $comparison  the array returned by BuildReportComparison */
    public function contextualize(array $comparison, User $user, string $language): AiContextResult;
}
