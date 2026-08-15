<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\CategoryDisplayNames;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * "Which of the following belongs to the {category} panel supported by LabLearn?"
 * Grounded in panels.json's own `tests[]` list — the authoritative "supported for
 * analysis" panel membership, not just any analyte tagged with that panel elsewhere.
 */
final class PanelMembershipFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'PANEL_MEMBERSHIP';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $members = $kb->panelAnalytes($category);
            $others = $this->otherCategoryAnalytes($kb, $category);
            if (count($members) < 1 || count($others) < 3) {
                continue;
            }
            foreach ($members as $analyte) {
                $distractors = DeterministicSelector::pick(
                    $others,
                    3,
                    "panel-membership|{$category->value}|{$analyte->id}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );

                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => 'Which of the following belongs to the '.CategoryDisplayNames::en($category).' panel supported by LabLearn?',
                        'ar' => 'أي مما يلي ينتمي إلى لوحة '.CategoryDisplayNames::ar($category).' المدعومة في LabLearn؟',
                    ],
                    options: $this->buildOptions(
                        ['en' => $analyte->name, 'ar' => $analyte->nameAr],
                        array_map(static fn (KbsAnalyte $a): array => ['en' => $a->name, 'ar' => $a->nameAr], $distractors),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "{$analyte->name} is explicitly listed in the current KBS configuration as part of the ".CategoryDisplayNames::en($category).' panel.',
                        'ar' => "{$analyte->nameAr} مدرج صراحةً في إعدادات KBS الحالية ضمن لوحة ".CategoryDisplayNames::ar($category).'.',
                    ],
                    sourceType: 'PANEL',
                    sourceId: $category->value,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$analyte->id}:PANEL_MEMBERSHIP:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }

    /** @return list<KbsAnalyte> */
    private function otherCategoryAnalytes(KbsKnowledgeBase $kb, ReportTestCategory $exclude): array
    {
        $others = [];
        foreach (ReportTestCategory::cases() as $category) {
            if ($category === $exclude) {
                continue;
            }
            array_push($others, ...$kb->panelAnalytes($category));
        }

        return $others;
    }
}
