<?php

namespace App\Services\Quiz;

use App\Models\Analysis;
use App\Models\Report;
use App\Models\VerifiedResultSet;
use Illuminate\Support\Collection;

/**
 * Interface left for Phase 3B.3 KBS-driven Case-Specific question generation.
 *
 * Phase 3B.2 only binds NullCaseSpecificQuestionProvider (always empty), so every quiz
 * session built in this phase has actual_case_specific_count = 0 and is General-only.
 * A later phase swaps the bound implementation (see AppServiceProvider::register()) for
 * one that builds questions from $analysis->conclusions()/$analysis->ruleTraces()/the
 * verified evidence - StartQuizSession and every caller of this interface stay unchanged.
 *
 * @see NullCaseSpecificQuestionProvider
 */
interface CaseSpecificQuestionProvider
{
    /**
     * Return at most $limit Case-Specific question drafts for this report/verified set
     * (and analysis, when one already exists). Must never fabricate content merely to
     * reach $limit - returning fewer than $limit (including zero) is expected and
     * accepted per the Dynamic Quiz Size Policy.
     *
     * @return Collection<int, array{
     *     question_text: array<string, string>,
     *     options: array<string, array<string, string>>,
     *     option_order: list<string>,
     *     correct_option_id: string,
     *     explanation: array<string, string>,
     *     case_specific_template_id?: string|null,
     *     case_specific_template_version?: int|null,
     *     evidence?: array<int, mixed>|null,
     *     rule_code?: string|null,
     *     analyte_refs?: array<int, mixed>|null,
     * }>
     */
    public function provide(Report $report, VerifiedResultSet $set, ?Analysis $analysis, int $limit): Collection;
}
