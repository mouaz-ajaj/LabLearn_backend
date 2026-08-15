<?php

namespace App\Services\Quiz\GeneralQuestions\TemplateFamilies;

use App\Enums\ReportTestCategory;
use App\Services\Quiz\GeneralQuestions\DeterministicSelector;
use App\Services\Quiz\GeneralQuestions\GeneratedGeneralQuestion;
use App\Services\Quiz\GeneralQuestions\Kbs\KbsKnowledgeBase;

/**
 * "Which classification can the current system assign to {analyte}?" Reachable
 * statuses are transcribed directly from kbs/core/classifier.py's actual classifier
 * functions (not assumed): random_glucose never returns "normal"/"prediabetes", hba1c
 * never returns "low", most analytes only ever return low/normal/high. Distractors are
 * statuses that ARE reachable somewhere in the KBS but never for this specific
 * analyte's own classifier — plausible, unambiguous wrong answers, never an
 * unreachable-everywhere status.
 */
final class StatusClassificationFamily implements GeneralQuestionTemplateFamily
{
    use BuildsOptionSets;

    private const STATUS_LABEL_EN = [
        'low' => 'Low', 'normal' => 'Normal', 'high' => 'High',
        'prediabetes' => 'Prediabetes range', 'diabetes' => 'Diabetes range', 'indeterminate' => 'Indeterminate',
    ];

    private const STATUS_LABEL_AR = [
        'low' => 'منخفضة', 'normal' => 'طبيعية', 'high' => 'مرتفعة',
        'prediabetes' => 'ضمن مجال ما قبل السكري', 'diabetes' => 'ضمن مجال السكري', 'indeterminate' => 'غير حاسمة',
    ];

    private const ALL_STATUSES = ['low', 'normal', 'high', 'prediabetes', 'diabetes', 'indeterminate'];

    /** @var array<string, list<string>> */
    private const REACHABLE_BY_CLASSIFIER = [
        'glucose_fasting' => ['low', 'normal', 'prediabetes', 'diabetes'],
        'glucose_random' => ['low', 'indeterminate', 'diabetes'],
        'glucose_postprandial' => ['low', 'normal', 'prediabetes', 'diabetes'],
        'glucose_ogtt_2h' => ['low', 'normal', 'prediabetes', 'diabetes'],
        'hba1c' => ['normal', 'prediabetes', 'diabetes'],
    ];

    public function code(): string
    {
        return 'STATUS_CLASSIFICATION';
    }

    public function generate(KbsKnowledgeBase $kb, string $generatorVersion): iterable
    {
        foreach (ReportTestCategory::cases() as $category) {
            foreach ($kb->allAnalytes($category) as $analyte) {
                $reachable = $analyte->classifier !== null
                    ? (self::REACHABLE_BY_CLASSIFIER[$analyte->classifier] ?? null)
                    : ['low', 'normal', 'high'];
                if ($reachable === null) {
                    continue;
                }
                $unreachable = array_values(array_diff(self::ALL_STATUSES, $reachable));
                if (count($unreachable) < 3) {
                    continue;
                }
                $correctStatus = DeterministicSelector::pick(
                    $reachable, 1, "status-correct|{$category->value}|{$analyte->id}",
                    static fn (string $s): string => $s,
                )[0];
                $distractorStatuses = DeterministicSelector::pick(
                    $unreachable, 3, "status-distractors|{$category->value}|{$analyte->id}",
                    static fn (string $s): string => $s,
                );

                yield new GeneratedGeneralQuestion(
                    category: $category,
                    questionText: [
                        'en' => "Which classification can the current LabLearn system assign to {$analyte->name}?",
                        'ar' => "ما التصنيف الذي يمكن أن يمنحه نظام LabLearn الحالي لـ{$analyte->nameAr}؟",
                    ],
                    options: $this->buildOptions(
                        ['en' => self::STATUS_LABEL_EN[$correctStatus], 'ar' => self::STATUS_LABEL_AR[$correctStatus]],
                        array_map(static fn (string $s): array => ['en' => self::STATUS_LABEL_EN[$s], 'ar' => self::STATUS_LABEL_AR[$s]], $distractorStatuses),
                    ),
                    correctOptionId: 'a',
                    explanation: [
                        'en' => "The current classifier for {$analyte->name} can produce a \"".self::STATUS_LABEL_EN[$correctStatus].'" result; the other options are not classifications this specific analyte\'s classifier ever produces.',
                        'ar' => 'يمكن لمصنّف '.$analyte->nameAr.' الحالي إنتاج تصنيف "'.self::STATUS_LABEL_AR[$correctStatus].'"؛ أما الخيارات الأخرى فليست من التصنيفات التي ينتجها مصنّف هذا المحلل تحديدًا.',
                    ],
                    sourceType: 'CLASSIFICATION',
                    sourceId: $analyte->id,
                    templateFamily: $this->code(),
                    stableSourceKey: "{$category->value}:{$analyte->id}:STATUS_CLASSIFICATION:{$generatorVersion}",
                    generatorVersion: $generatorVersion,
                );
            }
        }
    }
}
