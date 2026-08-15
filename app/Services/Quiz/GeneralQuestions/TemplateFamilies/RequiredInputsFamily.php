<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\CategoryDisplayNames;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * "Which analyte is part of the minimum required {category} input set in the current
 * KBS?" — strictly from panels.json's own `required[]` array, never a medical
 * inference. Categories with an empty required[] (DIABETES, currently) are skipped
 * entirely: there is no required/optional distinction to test.
 */
final class RequiredInputsFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'REQUIRED_INPUTS';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $requiredIds = $kb->requiredAnalyteIds($category);
            if ($requiredIds === []) {
                continue;
            }
            $panel = $kb->panelAnalytes($category);
            $optional = array_values(array_filter($panel, static fn (KbsAnalyte $a): bool => ! in_array($a->id, $requiredIds, true)));
            if (count($optional) < 3) {
                continue;
            }
            foreach ($requiredIds as $requiredId) {
                $analyte = $kb->analyte($requiredId);
                if ($analyte === null) {
                    continue;
                }
                $distractors = DeterministicSelector::pick(
                    $optional, 3, "required-inputs|{$category->value}|{$requiredId}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );
                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => 'Which analyte is part of the minimum required '.CategoryDisplayNames::en($category).' input set in the current KBS?',
                        'ar' => 'أي محلل يُعد جزءًا من الحد الأدنى للمدخلات المطلوبة للوحة '.CategoryDisplayNames::ar($category).' في نظام KBS الحالي؟',
                    ],
                    options: $this->buildOptions(
                        ['en' => $analyte->name, 'ar' => $analyte->nameAr],
                        array_map(static fn (KbsAnalyte $a): array => ['en' => $a->name, 'ar' => $a->nameAr], $distractors),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "The current KBS panel configuration lists {$analyte->name} in the required set for ".CategoryDisplayNames::en($category).', while the other options are optional/supporting analytes for this panel.',
                        'ar' => "يُدرج إعداد لوحة KBS الحالي {$analyte->nameAr} ضمن المجموعة المطلوبة للوحة ".CategoryDisplayNames::ar($category).'، بينما الخيارات الأخرى محللات اختيارية داعمة لهذه اللوحة.',
                    ],
                    sourceType: 'PANEL',
                    sourceId: $category->value,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$requiredId}:REQUIRED_INPUTS:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }
}
