<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsRule;

/**
 * "Which conclusion is associated with the following supported KBS condition:
 * {findings}?" — the reverse direction of PatternConditionRecognitionFamily (findings
 * -> pattern name instead of pattern name -> findings). Genuinely different recall
 * direction, not a near-duplicate: a student can know one direction without the other.
 */
final class RuleConclusionMatchingFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;
    use PicksDistinctConditionRules;

    public function code(): string
    {
        return 'RULE_CONCLUSION_MATCHING';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $rules = $this->representableRules($kb, $category);
            if (count($rules) < 4) {
                continue;
            }
            $distinctRules = $this->distinctByConditionId($rules);
            foreach ($rules as $rule) {
                $others = array_values(array_filter($distinctRules, static fn (KbsRule $r): bool => $r->conditionId !== $rule->conditionId));
                if (count($others) < 3) {
                    continue;
                }
                $distractorRules = DeterministicSelector::pick(
                    $others, 3, "rule-conclusion|{$category->value}|{$rule->code}",
                    static fn (KbsRule $r): string => $r->code,
                );

                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => "Which conclusion is associated with the following supported KBS condition: {$rule->conditionPhraseEn}?",
                        'ar' => "ما الاستنتاج المرتبط بهذا الشرط المدعوم في KBS: {$rule->conditionPhraseAr}؟",
                    ],
                    options: $this->buildOptions(
                        ['en' => $rule->patternNameEn, 'ar' => $rule->patternNameAr],
                        array_map(static fn (KbsRule $r): array => ['en' => $r->patternNameEn, 'ar' => $r->patternNameAr], $distractorRules),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "Rule {$rule->code} maps this exact combination ({$rule->conditionPhraseEn}) to \"{$rule->patternNameEn}\".",
                        'ar' => "تربط القاعدة {$rule->code} هذا المزيج تحديدًا ({$rule->conditionPhraseAr}) بنمط \"{$rule->patternNameAr}\".",
                    ],
                    sourceType: 'RULE',
                    sourceId: $rule->code,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$rule->code}:RULE_CONCLUSION_MATCHING:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }
}
