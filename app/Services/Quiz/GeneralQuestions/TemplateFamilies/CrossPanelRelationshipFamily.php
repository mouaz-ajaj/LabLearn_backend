<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\CategoryDisplayNames;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsAnalyte;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * Deliberately small: the current KBS has exactly one active rule (GLUX_R001, "possible
 * a1c interference context") whose trigger genuinely spans two categories — it reads a
 * CBC analyte (hemoglobin) as supporting context for a DIABETES-category pattern. This
 * family only fires for rules KbsKnowledgeBase::crossPanelAnalyteIds() actually finds
 * cross-category evidence for; it does not fabricate cross-panel associations the KBS
 * does not have.
 */
final class CrossPanelRelationshipFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    public function code(): string
    {
        return 'CROSS_PANEL_RELATIONSHIP';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            foreach ($kb->rules($category) as $rule) {
                $crossIds = $kb->crossPanelAnalyteIds($rule);
                foreach ($crossIds as $crossId) {
                    $crossAnalyte = $kb->analyte($crossId);
                    if ($crossAnalyte === null) {
                        continue;
                    }
                    $sameCategoryPool = array_values(array_filter(
                        $kb->allAnalytes($crossAnalyte->category),
                        static fn (KbsAnalyte $a): bool => $a->id !== $crossId,
                    ));
                    if (count($sameCategoryPool) < 3) {
                        continue;
                    }
                    $distractors = DeterministicSelector::pick(
                        $sameCategoryPool, 3, "cross-panel|{$rule->code}|{$crossId}",
                        static fn (KbsAnalyte $a): string => $a->id,
                    );

                    yield new GeneratedGeneralQuestion(
                        category: $category,
                        questionText: [
                            'en' => 'Which '.CategoryDisplayNames::en($crossAnalyte->category).' analyte is used as supporting context by an active '.CategoryDisplayNames::en($category)."-category KBS pattern (\"{$rule->patternNameEn}\")?",
                            'ar' => 'أي محلل من فئة '.CategoryDisplayNames::ar($crossAnalyte->category).' يُستخدم كسياق داعم ضمن نمط نشط في فئة '.CategoryDisplayNames::ar($category)." من KBS (\"{$rule->patternNameAr}\")؟",
                        ],
                        options: $this->buildOptions(
                            ['en' => $crossAnalyte->name, 'ar' => $crossAnalyte->nameAr],
                            array_map(static fn (KbsAnalyte $a): array => ['en' => $a->name, 'ar' => $a->nameAr], $distractors),
                        ),
                        correctOptionId: 'a',
                        explanation: [
                            'en' => "Rule {$rule->code} ({$rule->patternNameEn}) reads {$crossAnalyte->name} — a ".CategoryDisplayNames::en($crossAnalyte->category)." analyte — as part of its condition: {$rule->conditionPhraseEn}.",
                            'ar' => "تستخدم القاعدة {$rule->code} ({$rule->patternNameAr}) محلل {$crossAnalyte->nameAr} — وهو من فئة ".CategoryDisplayNames::ar($crossAnalyte->category)." — ضمن شرطها: {$rule->conditionPhraseAr}.",
                        ],
                        sourceType: 'RELATIONSHIP',
                        sourceId: $rule->code,
                        templateFamily: $this->code(),
                        stableSourceKey: "{$category->value}:{$rule->code}:{$crossId}:CROSS_PANEL_RELATIONSHIP:{$generatorVersion}",
                        generatorVersion: $generatorVersion,
                    );
                }
            }
        }
    }
}
