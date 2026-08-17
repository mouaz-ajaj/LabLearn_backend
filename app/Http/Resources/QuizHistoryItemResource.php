<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight quiz-history list item (Phase 4D). Deliberately excludes question
 * snapshots/answers - those belong to the detail screen (GET /quiz/{quiz}, reused
 * as-is), not the list. Also excludes user_id, identity_key, verified_result_set_id,
 * and analysis_id, none of which the history list needs.
 */
class QuizHistoryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $total = (int) $this->actual_total;
        $score = (int) $this->score;

        return [
            'id' => $this->getKey(),
            'report_id' => $this->report_id,
            'test_category' => $this->report_category,
            'status' => $this->status->value,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'score' => $score,
            'total' => $total,
            'percentage' => $total > 0 ? round($score / $total * 100, 1) : null,
            'general_count' => $this->actual_general_count,
            'case_specific_count' => $this->actual_case_specific_count,
        ];
    }
}
