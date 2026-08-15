<?php

namespace App\Services\Quiz\GeneralQuestions\Kbs;

use App\Enums\ReportTestCategory;

/**
 * One active, supported KBS pattern rule, reduced to exactly what the General
 * Question templates need: which analyte+status pairs must jointly hold for it to
 * fire, and a natural bilingual sentence describing that combination.
 *
 * `jointTriggers` only ever contains pairs that are genuinely required together (an
 * `all` clause, or an `any` clause whose `min_matched` equals its own size, which is
 * logically an `all`). Rules whose real trigger is "any ONE of several" are excluded
 * from `jointTriggers`-based templates entirely (see KbsKnowledgeBase) — phrasing an
 * "any one" rule as a required combination would misstate how it actually fires.
 */
final class KbsRule
{
    /**
     * @param  list<KbsRuleTrigger>  $jointTriggers
     */
    public function __construct(
        public readonly string $code,
        public readonly ReportTestCategory $category,
        public readonly string $conditionId,
        public readonly bool $active,
        public readonly int $weight,
        public readonly array $jointTriggers,
        public readonly string $conditionPhraseEn,
        public readonly string $conditionPhraseAr,
        public readonly string $patternNameEn,
        public readonly string $patternNameAr,
    ) {}

    /** @return list<string> */
    public function jointAnalyteIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (KbsRuleTrigger $trigger): string => $trigger->analyteId,
            $this->jointTriggers,
        )));
    }

    public function isCleanPair(): bool
    {
        return count($this->jointAnalyteIds()) === 2;
    }
}
