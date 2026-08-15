<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * "Which of the following labels can refer to {name}?" using only aliases from
 * tests.json/liver_tests.json that KbsKnowledgeBase already filtered as unambiguous
 * (excludes analyte_disambiguation.json base_aliases, and anything identical to the
 * analyte's own name/short_name). Analytes with no safe alias are skipped entirely.
 */
final class AliasRecognitionFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'ALIAS_RECOGNITION';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $pool = $kb->allAnalytes($category);
            $eligible = array_values(array_filter($pool, static fn (KbsAnalyte $a): bool => $a->safeAliases !== []));
            if (count($eligible) < 1 || count($pool) < 4) {
                continue;
            }
            foreach ($eligible as $analyte) {
                $others = array_values(array_filter($pool, static fn (KbsAnalyte $a): bool => $a->id !== $analyte->id));
                if (count($others) < 3) {
                    continue;
                }
                $alias = DeterministicSelector::pick(
                    $analyte->safeAliases, 1, "alias|{$category->value}|{$analyte->id}",
                    static fn (string $a): string => $a,
                )[0];
                $distractorAnalytes = DeterministicSelector::pick(
                    $others, 3, "alias-distractors|{$category->value}|{$analyte->id}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );
                $distractors = array_map(
                    static fn (KbsAnalyte $a): string => $a->safeAliases[0] ?? $a->shortName,
                    $distractorAnalytes,
                );

                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => "Which of the following labels can refer to {$analyte->name} in the current LabLearn KBS?",
                        'ar' => "أي من التسميات التالية يمكن أن تشير إلى {$analyte->nameAr} في نظام LabLearn الحالي؟",
                    ],
                    options: $this->buildOptions(
                        ['en' => $alias, 'ar' => $alias],
                        array_map(static fn (string $label): array => ['en' => $label, 'ar' => $label], $distractors),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "The KBS analyte catalog lists \"{$alias}\" as an accepted alias for {$analyte->name}.",
                        'ar' => "يُدرج فهرس محللات KBS \"{$alias}\" كاسم بديل مقبول لـ{$analyte->nameAr}.",
                    ],
                    sourceType: 'ANALYTE',
                    sourceId: $analyte->id,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$analyte->id}:ALIAS_RECOGNITION:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }
}
