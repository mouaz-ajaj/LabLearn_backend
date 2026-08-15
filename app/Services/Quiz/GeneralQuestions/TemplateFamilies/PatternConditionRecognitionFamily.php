<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsRule;

/**
 * "Which combination of findings is associated with the KBS-supported pattern
 * '{name}'?" (pattern name -> findings direction). Only rules with a non-empty
 * jointTriggers set participate — a rule whose real trigger is "any one of several"
 * has no single honest "combination" to describe (see
 * KbsKnowledgeBase::jointTriggersFromWhen). Distractors are drawn from OTHER rules
 * with a DIFFERENT condition_id, so no distractor is accidentally also correct.
 */
final class PatternConditionRecognitionFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;
    use PicksDistinctConditionRules;

    public function code(): string
    {
        return 'PATTERN_CONDITION_RECOGNITION';
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
                    $others, 3, "pattern-condition|{$category->value}|{$rule->code}",
                    static fn (KbsRule $r): string => $r->code,
                );

                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => "Which combination of findings is associated with the KBS-supported pattern \"{$rule->patternNameEn}\"?",
                        'ar' => "ما مجموعة الاكتشافات المرتبطة بالنمط المدعوم في KBS: \"{$rule->patternNameAr}\"؟",
                    ],
                    options: $this->buildOptions(
                        ['en' => $rule->conditionPhraseEn, 'ar' => $rule->conditionPhraseAr],
                        array_map(static fn (KbsRule $r): array => ['en' => $r->conditionPhraseEn, 'ar' => $r->conditionPhraseAr], $distractorRules),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "Rule {$rule->code} supports \"{$rule->patternNameEn}\" specifically when {$rule->conditionPhraseEn}.",
                        'ar' => "تدعم القاعدة {$rule->code} نمط \"{$rule->patternNameAr}\" تحديدًا عندما تكون {$rule->conditionPhraseAr}.",
                    ],
                    sourceType: 'RULE',
                    sourceId: $rule->code,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$rule->code}:PATTERN_CONDITION_RECOGNITION:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }
}
