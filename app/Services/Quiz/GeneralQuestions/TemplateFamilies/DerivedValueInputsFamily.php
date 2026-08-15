<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * "Which two inputs are needed by the current system to derive {name}?" — inputs are
 * read from tests.json's own `formula` string (e.g. "wbc * neutrophils_percent / 100"
 * for ANC) for the five CBC differential values, matching
 * kbs/core/derived_values.py::calculate_derived_values exactly. liver_tests.json's
 * indirect_bilirubin has `"derived": true` but no formula string, so its two inputs
 * (total_bilirubin, direct_bilirubin) are transcribed directly from that same Python
 * function's bilirubin branch. This single family covers what a separate "Derived
 * Value Meaning/Relationship" family would have asked too (which specific measurement
 * pairs with WBC) — both would be grounded in the identical formula data and would
 * produce near-duplicate questions, so that family was folded into this one instead
 * of being generated twice.
 */
final class DerivedValueInputsFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'DERIVED_VALUE_INPUTS';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $pool = $kb->allAnalytes($category);
            foreach ($pool as $analyte) {
                if (! $analyte->derived) {
                    continue;
                }
                $inputs = $this->resolveInputs($analyte, $kb);
                if ($inputs === null) {
                    continue;
                }
                [$primary, $secondary] = $inputs;
                $candidates = array_values(array_filter(
                    $pool,
                    static fn (KbsAnalyte $z): bool => $z->id !== $primary->id && $z->id !== $secondary->id && $z->id !== $analyte->id && ! $z->derived,
                ));
                if (count($candidates) < 3) {
                    continue;
                }
                $distractorZ = DeterministicSelector::pick(
                    $candidates, 3, "derived-inputs|{$category->value}|{$analyte->id}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );

                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => "Which two inputs are needed by the current system to derive {$analyte->name}?",
                        'ar' => "ما المدخلان اللازمان في النظام الحالي لاشتقاق {$analyte->nameAr}؟",
                    ],
                    options: $this->buildOptions(
                        ['en' => "{$primary->name} and {$secondary->name}", 'ar' => "{$primary->nameAr} و{$secondary->nameAr}"],
                        array_map(static fn (KbsAnalyte $z): array => [
                            'en' => "{$primary->name} and {$z->name}", 'ar' => "{$primary->nameAr} و{$z->nameAr}",
                        ], $distractorZ),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "The current system calculates {$analyte->name} from {$primary->name} and {$secondary->name}.",
                        'ar' => "يحسب النظام الحالي {$analyte->nameAr} من {$primary->nameAr} و{$secondary->nameAr}.",
                    ],
                    sourceType: 'DERIVED_VALUE',
                    sourceId: $analyte->id,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$analyte->id}:DERIVED_VALUE_INPUTS:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }

    /** @return array{0: KbsAnalyte, 1: KbsAnalyte}|null */
    private function resolveInputs(KbsAnalyte $analyte, KbsKnowledgeBase $kb): ?array
    {
        if ($analyte->id === 'indirect_bilirubin') {
            $primary = $kb->analyte('total_bilirubin');
            $secondary = $kb->analyte('direct_bilirubin');

            return ($primary && $secondary) ? [$primary, $secondary] : null;
        }
        if ($analyte->formula === null) {
            return null;
        }
        preg_match_all('/[a-z][a-z0-9_]*/', $analyte->formula, $matches);
        $ids = array_values(array_unique($matches[0] ?? []));
        $resolved = array_values(array_filter(array_map(
            fn (string $id): ?KbsAnalyte => $kb->analyte($id),
            $ids,
        )));

        return count($resolved) === 2 ? [$resolved[0], $resolved[1]] : null;
    }
}
