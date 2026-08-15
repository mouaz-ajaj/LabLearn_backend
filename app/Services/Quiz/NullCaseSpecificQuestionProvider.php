<?php

namespace App\Services\Quiz;

use App\Models\Analysis;
use App\Models\Report;
use App\Models\VerifiedResultSet;
use Illuminate\Support\Collection;

/**
 * Phase 3B.2 placeholder: real Case-Specific questions require KBS-driven generation
 * from verified evidence/conclusions, which is explicitly out of scope for this phase.
 * This implementation always returns an empty collection so quiz sessions gracefully
 * accept a smaller, General-only quiz (per the Dynamic Quiz Size Policy) instead of
 * fabricating medical content just to reach the preferred target.
 */
class NullCaseSpecificQuestionProvider implements CaseSpecificQuestionProvider
{
    public function provide(Report $report, VerifiedResultSet $set, ?Analysis $analysis, int $limit): Collection
    {
        return collect();
    }
}
