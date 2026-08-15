<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * Two directions, both grounded in tests.json/liver_tests.json's own `short_name`:
 * "What does {short_name} refer to?" (forward) and "Which abbreviation corresponds to
 * {name}?" (reverse). Only analytes with a short_name distinct from their full name
 * participate — every analyte in the current KBS qualifies.
 */
final class AbbreviationFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'ABBREVIATION';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $pool = $kb->allAnalytes($category);
            $eligible = array_values(array_filter(
                $pool,
                static fn (KbsAnalyte $a): bool => $a->shortName !== '' && $a->shortName !== $a->name,
            ));
            if (count($eligible) < 4) {
                continue;
            }
            foreach ($eligible as $analyte) {
                $otherCandidates = array_values(array_filter($eligible, static fn (KbsAnalyte $a): bool => $a->id !== $analyte->id));

                $nameDistractors = DeterministicSelector::pick(
                    $otherCandidates, 3, "abbreviation-fwd|{$category->value}|{$analyte->id}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );
                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => "What does {$analyte->shortName} refer to in the current LabLearn KBS?",
                        'ar' => "إلى ماذا يشير الاختصار {$analyte->shortName} في نظام LabLearn الحالي؟",
                    ],
                    options: $this->buildOptions(
                        ['en' => $analyte->name, 'ar' => $analyte->nameAr],
                        array_map(static fn (KbsAnalyte $a): array => ['en' => $a->name, 'ar' => $a->nameAr], $nameDistractors),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "The KBS configuration lists {$analyte->shortName} as the short name for {$analyte->name}.",
                        'ar' => "يُدرج إعداد KBS {$analyte->shortName} كاختصار لـ{$analyte->nameAr}.",
                    ],
                    sourceType: 'ANALYTE',
                    sourceId: $analyte->id,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$analyte->id}:ABBREVIATION_FORWARD:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );

                $shortDistractors = DeterministicSelector::pick(
                    $otherCandidates, 3, "abbreviation-rev|{$category->value}|{$analyte->id}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );
                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => "Which abbreviation corresponds to {$analyte->name} in the current LabLearn KBS?",
                        'ar' => "ما الاختصار المطابق لـ{$analyte->nameAr} في نظام LabLearn الحالي؟",
                    ],
                    options: $this->buildOptions(
                        ['en' => $analyte->shortName, 'ar' => $analyte->shortName],
                        array_map(static fn (KbsAnalyte $a): array => ['en' => $a->shortName, 'ar' => $a->shortName], $shortDistractors),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "The KBS configuration lists {$analyte->shortName} as the short name for {$analyte->name}.",
                        'ar' => "يُدرج إعداد KBS {$analyte->shortName} كاختصار لـ{$analyte->nameAr}.",
                    ],
                    sourceType: 'ANALYTE',
                    sourceId: $analyte->id,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$analyte->id}:ABBREVIATION_REVERSE:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }
}
