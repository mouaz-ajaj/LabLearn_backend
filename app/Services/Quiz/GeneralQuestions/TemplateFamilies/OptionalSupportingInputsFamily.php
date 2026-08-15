<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\CategoryDisplayNames;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * Inverse of RequiredInputsFamily: "Which {category} analyte may contribute to
 * additional rule evaluation but is not one of the global required inputs?" Skipped
 * for categories with no required[] at all (the required/optional distinction does
 * not exist there) or fewer than 3 required analytes (not enough distractors).
 */
final class OptionalSupportingInputsFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'OPTIONAL_SUPPORTING_INPUTS';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $requiredIds = $kb->requiredAnalyteIds($category);
            if (count($requiredIds) < 3) {
                continue;
            }
            $requiredAnalytes = array_values(array_filter(array_map(
                fn (string $id): ?KbsAnalyte => $kb->analyte($id),
                $requiredIds,
            )));
            $panel = $kb->panelAnalytes($category);
            $optional = array_values(array_filter($panel, static fn (KbsAnalyte $a): bool => ! in_array($a->id, $requiredIds, true)));

            foreach ($optional as $analyte) {
                $distractors = DeterministicSelector::pick(
                    $requiredAnalytes, 3, "optional-inputs|{$category->value}|{$analyte->id}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );
                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => 'Which '.CategoryDisplayNames::en($category)." analyte may contribute to additional rule evaluation but is not one of the current KBS's global required inputs?",
                        'ar' => 'أي محلل من محللات لوحة '.CategoryDisplayNames::ar($category).' قد يسهم في تقييم قواعد إضافية لكنه ليس ضمن المدخلات المطلوبة عالميًا في نظام KBS الحالي؟',
                    ],
                    options: $this->buildOptions(
                        ['en' => $analyte->name, 'ar' => $analyte->nameAr],
                        array_map(static fn (KbsAnalyte $a): array => ['en' => $a->name, 'ar' => $a->nameAr], $distractors),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "{$analyte->name} is part of the ".CategoryDisplayNames::en($category).' panel but is not in its required set, while the other options are the required analytes for this panel.',
                        'ar' => "{$analyte->nameAr} جزء من لوحة ".CategoryDisplayNames::ar($category).' لكنه ليس ضمن مجموعتها المطلوبة، بينما الخيارات الأخرى هي المحللات المطلوبة لهذه اللوحة.',
                    ],
                    sourceType: 'PANEL',
                    sourceId: $category->value,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$analyte->id}:OPTIONAL_SUPPORTING_INPUTS:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }
}
