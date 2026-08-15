<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsRule;

/**
 * "Which pair of analytes is evaluated together by the KBS pattern '{name}'?" — only
 * for active rules whose real trigger is exactly two analytes required jointly
 * (KbsRule::isCleanPair(), which already excludes "any one of several" rules). This
 * also covers what a separate "Same-Panel Relationship" family would have asked (both
 * would draw from the identical pool of 2-analyte composite active rules given the
 * current KBS's declarative structure), so that family was consolidated here instead
 * of being implemented twice with near-duplicate content.
 *
 * Distractor pairs keep the same first analyte and vary the second, excluding any
 * second analyte that would ALSO form a genuinely valid pair for another active rule
 * (so no distractor is accidentally also defensible).
 */
final class RuleInputRecognitionFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'RULE_INPUT_RECOGNITION';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $rules = array_values(array_filter($kb->rules($category), static fn (KbsRule $r): bool => $r->isCleanPair()));
            if ($rules === []) {
                continue;
            }
            $validPairKeys = array_map(
                fn (KbsRule $r): string => $this->pairKey($r->jointAnalyteIds()),
                $rules,
            );
            $panel = $kb->allAnalytes($category);

            foreach ($rules as $rule) {
                [$idX, $idY] = $rule->jointAnalyteIds();
                $x = $kb->analyte($idX);
                $y = $kb->analyte($idY);
                if ($x === null || $y === null) {
                    continue;
                }
                $candidates = array_values(array_filter(
                    $panel,
                    fn (KbsAnalyte $z) => $z->id !== $idX && $z->id !== $idY
                        && ! in_array($this->pairKey([$idX, $z->id]), $validPairKeys, true),
                ));
                if (count($candidates) < 3) {
                    continue;
                }
                $distractorZ = DeterministicSelector::pick(
                    $candidates, 3, "rule-input-pair|{$category->value}|{$rule->code}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );

                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => "Which pair of analytes is evaluated together by the KBS-supported pattern \"{$rule->patternNameEn}\"?",
                        'ar' => "أي زوج من المحللات يُقيَّم معًا ضمن النمط المدعوم في KBS: \"{$rule->patternNameAr}\"؟",
                    ],
                    options: $this->buildOptions(
                        ['en' => "{$x->name} and {$y->name}", 'ar' => "{$x->nameAr} و{$y->nameAr}"],
                        array_map(static fn (KbsAnalyte $z): array => [
                            'en' => "{$x->name} and {$z->name}", 'ar' => "{$x->nameAr} و{$z->nameAr}",
                        ], $distractorZ),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "Rule {$rule->code} requires {$x->name} together with {$y->name} — {$rule->conditionPhraseEn}.",
                        'ar' => "تتطلب القاعدة {$rule->code} اجتماع {$x->nameAr} مع {$y->nameAr} — {$rule->conditionPhraseAr}.",
                    ],
                    sourceType: 'RULE',
                    sourceId: $rule->code,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$rule->code}:RULE_INPUT_RECOGNITION:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }

    /** @param list<string> $ids */
    private function pairKey(array $ids): string
    {
        $sorted = $ids;
        sort($sorted);

        return implode('+', $sorted);
    }
}
