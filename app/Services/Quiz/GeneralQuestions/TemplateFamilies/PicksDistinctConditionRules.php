<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsRule;

/** Shared by the two families built on KbsRule's conditionPhrase/patternName pairs. */
trait PicksDistinctConditionRules
{
    /** Active rules in this category whose trigger is a real, honestly-describable combination. */
    private function representableRules(KbsKnowledgeBase $kb, ReportTestCategory $category): array
    {
        return array_values(array_filter(
            $kb->rules($category),
            static fn (KbsRule $r): bool => $r->jointTriggers !== [],
        ));
    }

    /**
     * Keeps at most one rule per condition_id. Several rules can legitimately share a
     * condition_id (e.g. R002/R003 both support microcytic_anemia_pattern via
     * different evidence) — fine as separate QUESTIONS, but two of them can never
     * appear as two DIFFERENT options in the same question, since their pattern name
     * (or, for the reverse direction, their condition phrase reuse) would collide and
     * produce a duplicate-option MCQ.
     *
     * @param  list<KbsRule>  $rules
     * @return list<KbsRule>
     */
    private function distinctByConditionId(array $rules): array
    {
        $seen = [];
        $result = [];
        foreach ($rules as $rule) {
            if (isset($seen[$rule->conditionId])) {
                continue;
            }
            $seen[$rule->conditionId] = true;
            $result[] = $rule;
        }

        return $result;
    }
}
