<?php

namespace App\Services\Quiz;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\Report;
use App\Models\VerifiedResultSet;
use App\Services\Quiz\CaseSpecific\CaseSpecificTemplateCatalog;
use Illuminate\Support\Collection;

/**
 * Deterministic Case-Specific question generation from real, persisted KBS evidence.
 *
 * Pipeline: load the current Analysis's fired RuleTraces -> for each catalog template
 * in this report's category, check whether its trigger rule_code actually fired with
 * non-empty evidence -> reject unsupported candidates (never fabricate) -> take up to
 * $limit. There is no "while count < target: invent another question" loop anywhere
 * here — a category with fewer matching templates simply yields fewer (or zero)
 * Case-Specific questions, which StartQuizSession/FinalizeQuizSession already accept
 * as a smaller, evidence-safe quiz.
 */
class CaseSpecificQuestionBuilder implements CaseSpecificQuestionProvider
{
    public function __construct(
        private readonly CaseSpecificTemplateCatalog $catalog,
    ) {}

    public function provide(Report $report, VerifiedResultSet $set, ?Analysis $analysis, int $limit): Collection
    {
        if ($limit <= 0 || $analysis === null || $analysis->status !== AnalysisStatus::Succeeded) {
            return collect();
        }

        $firedTracesByRule = $analysis->ruleTraces
            ->where('fired', true)
            ->keyBy('rule_code');

        $selected = collect();
        foreach ($this->catalog->templatesFor($report->test_category) as $template) {
            if ($selected->count() >= $limit) {
                break;
            }
            $trace = $firedTracesByRule->get($template->ruleCode);
            if ($trace === null) {
                continue;
            }
            $evidence = $trace->evidence_json ?? [];
            if ($evidence === []) {
                // The rule fired but produced no traceable evidence to ground a
                // question in — skip rather than ask an unsupported question.
                continue;
            }
            $selected->push($template->buildDraft($evidence));
        }

        return $selected;
    }
}
