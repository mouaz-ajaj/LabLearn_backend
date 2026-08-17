<?php

namespace App\Enums;

/**
 * Keeps the ai_explanations cache table safely shared across future AI presentation
 * features without mixing their data - each row is scoped to exactly one task type.
 * Only RESULT_EXPLANATION exists as of Phase 4E; Phase 4C comparison contextualization
 * remains fully stateless/uncached and never writes to this table.
 */
enum AiTaskType: string
{
    case ResultExplanation = 'RESULT_EXPLANATION';
}
