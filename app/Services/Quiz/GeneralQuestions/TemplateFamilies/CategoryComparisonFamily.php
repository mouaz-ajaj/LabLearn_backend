<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\CategoryDisplayNames;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * "Which pair of analytes both belong to the {category} panel supported by
 * LabLearn?" — a pairwise variant deliberately shaped differently from
 * PanelMembershipFamily's single-analyte question, so the two families test distinct
 * skills (single-item recall vs. recognizing category cohesion of two items at once)
 * rather than repeating the same fact. Same-category pairs are built by walking the
 * official panel list two at a time; distractor pairs deliberately mix in an analyte
 * from one of the OTHER two categories, which is always a genuinely wrong pairing.
 */
final class CategoryComparisonFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'CATEGORY_COMPARISON';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            $panel = $kb->panelAnalytes($category);
            $otherCategoryAnalytes = $this->otherCategoryAnalytes($kb, $category);
            if (count($panel) < 2 || count($otherCategoryAnalytes) < 3) {
                continue;
            }
            $pairs = array_chunk($panel, 2);
            foreach ($pairs as $pair) {
                if (count($pair) !== 2) {
                    continue;
                }
                [$x, $y] = $pair;
                $sameCategoryDistractorCandidates = array_values(array_filter(
                    $panel,
                    static fn (KbsAnalyte $a): bool => $a->id !== $x->id && $a->id !== $y->id,
                ));
                $mixedZ = DeterministicSelector::pick(
                    $otherCategoryAnalytes, 3, "category-comparison|{$category->value}|{$x->id}|{$y->id}",
                    static fn (KbsAnalyte $a): string => $a->id,
                );
                if (count($mixedZ) < 3) {
                    continue;
                }

                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => 'Which pair of analytes both belong to the '.CategoryDisplayNames::en($category).' panel supported by LabLearn?',
                        'ar' => 'أي زوج من المحللات ينتمي كلاهما إلى لوحة '.CategoryDisplayNames::ar($category).' المدعومة في LabLearn؟',
                    ],
                    options: $this->buildOptions(
                        ['en' => "{$x->name} and {$y->name}", 'ar' => "{$x->nameAr} و{$y->nameAr}"],
                        array_map(fn (KbsAnalyte $z): array => [
                            'en' => "{$x->name} and {$z->name}",
                            'ar' => "{$x->nameAr} و{$z->nameAr}",
                        ], $mixedZ),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "{$x->name} and {$y->name} are both explicitly listed in the current KBS configuration as part of the ".CategoryDisplayNames::en($category).' panel; the other options each pair a '.CategoryDisplayNames::en($category).' analyte with one from a different category.',
                        'ar' => "{$x->nameAr} و{$y->nameAr} مدرجان معًا صراحةً في إعدادات KBS الحالية ضمن لوحة ".CategoryDisplayNames::ar($category).'، بينما تجمع بقية الخيارات محللًا من هذه اللوحة مع محلل من فئة مختلفة.',
                    ],
                    sourceType: 'PANEL',
                    sourceId: $category->value,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$x->id}:{$y->id}:CATEGORY_COMPARISON:{$generatorVersion}",
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
