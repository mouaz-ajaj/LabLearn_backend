<?php

namespace App\Contracts;

use App\Models\Analysis;
use App\Services\Ai\ResultExplanationResult;

/**
 * Swappable AI-provider boundary for result explanation (Phase 4E), mirroring
 * Phase 4C's AiContextualizer contract. Gemini is the only implementation today
 * (GeminiResultExplainer), but the caller depends only on this contract, so a future
 * provider change never touches the deterministic Analysis/cache logic. Role is
 * passed as an already-derived string ('student'|'regular') rather than a User model
 * - the caller derives it from the authenticated user, never from client input, and
 * implementations never need broader user identity than that single string.
 * Implementations must never throw; AI failure can never break a result request.
 */
interface ResultExplainer
{
    public function explain(Analysis $analysis, string $role, string $language): ResultExplanationResult;
}
