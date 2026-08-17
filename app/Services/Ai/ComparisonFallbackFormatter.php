<?php

namespace App\Services\Ai;

use App\Enums\ConclusionTransition;
use App\Enums\LabMovementClassification;
use App\Models\User;
use App\Services\Ai\MedicalContext\ComparisonMedicalContextResolver;
use App\Services\Comparison\GroupAnalyteChanges;

/**
 * Deterministic, bilingual, role-aware explanation built purely from facts Laravel
 * already computed - no medical interpretation attempted beyond what the Approved
 * Medical Context catalog already source-groundedly provides. Produces the exact same
 * v2 content schema as a validated Gemini response so the frontend renders both
 * identically. Used whenever Gemini is disabled, unreachable, or its output fails
 * validation - the comparison itself always succeeds regardless.
 *
 * 2026-08-17 redesign: no longer falls back to a flat per-analyte/per-rule-code dump -
 * uses the same GroupAnalyteChanges sections and pattern_transitions Gemini would have
 * received, so a Gemini outage still shows the user genuinely useful, structured,
 * change-focused content instead of regressing to a rigid essay.
 */
class ComparisonFallbackFormatter
{
    public function __construct(
        private readonly GroupAnalyteChanges $groupAnalyteChanges,
        private readonly ComparisonMedicalContextResolver $medicalContextResolver,
    ) {}

    /** @param  array<string, mixed>  $comparison  the array returned by BuildReportComparison
     * @return array<string, mixed>
     */
    public function format(array $comparison, User $user, string $language): array
    {
        $role = $user->role->value === 'student' ? 'student' : 'regular';
        $grouped = $this->groupAnalyteChanges->handle($comparison['analytes']);
        $resolvedGroups = $this->medicalContextResolver->buildLocalizedContext($comparison['pattern_transitions'], $language);

        $normalized = $this->findingSentences($grouped['normalized'], $language, 'normalized');
        $betterButStillAbnormal = $this->findingSentences($grouped['better_but_still_abnormal'], $language, 'better');
        $newOrWorse = $this->findingSentences($grouped['new_or_worse'], $language, 'worse');
        $persistentAbnormal = $this->findingSentences($grouped['persistent_abnormal'], $language, 'persistent');
        $patternChanges = $this->patternChangeSentences($comparison['pattern_transitions'], $language);

        $content = [
            'schema_version' => '2',
            'language' => $language,
            'role' => $role,
            'category' => $comparison['category'],
            'overall_picture' => $this->overallPicture(count($normalized), count($betterButStillAbnormal), count($newOrWorse), $language),
            'normalized_findings' => $normalized,
            'better_but_still_abnormal' => $betterButStillAbnormal,
            'new_or_worse_findings' => $newOrWorse,
            'pattern_changes' => $patternChanges,
            'interpretation' => $this->interpretation($resolvedGroups, $language),
            'unchanged_summary' => $this->unchangedSummary($grouped['unchanged_comparable_count'], $language),
            'limitations' => $this->limitations($language),
        ];

        if ($role === 'student') {
            $content['student_context'] = [
                'clinical_significance' => $this->clinicalSignificance($resolvedGroups, $language),
                'differential_context' => $this->flattenGroupItems($resolvedGroups, 'student_context.differential_considerations'),
                'interpretation_clues' => $this->flattenGroupItems($resolvedGroups, 'student_context.distinguishing_information'),
                'persistent_abnormalities' => $persistentAbnormal,
            ];
        }

        return $content;
    }

    /** @param array<int, array<string, mixed>> $items
     * @return array<int, array{analyte_id:string,text:string}>
     */
    private function findingSentences(array $items, string $language, string $kind): array
    {
        return array_map(function (array $item) use ($language, $kind): array {
            $name = $this->localizedField($item['display_name'] ?? null, $item['display_name_ar'] ?? null, $language);
            $classification = LabMovementClassification::from($item['lab_change_classification']);

            return ['analyte_id' => $item['analyte_id'], 'text' => $this->findingSentence($name, $classification, $kind, $language)];
        }, $items);
    }

    private function findingSentence(?string $name, LabMovementClassification $classification, string $kind, string $language): string
    {
        if ($language === 'ar') {
            return match (true) {
                $kind === 'normalized' => "عاد {$name} إلى المجال المرجعي بعد أن كان خارجه في التقرير الأقدم.",
                $kind === 'better' => "اتجه {$name} نحو المجال المرجعي مقارنة بالتقرير الأقدم، لكنه ما يزال خارجه.",
                $kind === 'worse' && $classification === LabMovementClassification::BecameAbnormal => "أصبح {$name} خارج المجال المرجعي في التقرير الأحدث بعد أن كان ضمنه.",
                $kind === 'worse' => "ابتعد {$name} أكثر عن المجال المرجعي مقارنة بالتقرير الأقدم.",
                default => "ما يزال {$name} خارج المجال المرجعي دون تغيّر مهم في المسافة عنه.",
            };
        }

        return match (true) {
            $kind === 'normalized' => "{$name} returned to the reference range after being outside it in the earlier report.",
            $kind === 'better' => "{$name} moved toward the reference range compared with the earlier report, but remains outside it.",
            $kind === 'worse' && $classification === LabMovementClassification::BecameAbnormal => "{$name} moved outside the reference range in the latest report, having been within it before.",
            $kind === 'worse' => "{$name} moved farther from the reference range compared with the earlier report.",
            default => "{$name} remains outside the reference range, with no meaningful change in distance from it.",
        };
    }

    /** @param array<int, array<string, mixed>> $patternTransitions
     * @return array<int, array{conclusion_code:string,transition:string,text:string}>
     */
    private function patternChangeSentences(array $patternTransitions, string $language): array
    {
        return array_map(function (array $t) use ($language): array {
            $title = $this->localized($t['title'], $language);
            $transition = ConclusionTransition::from($t['transition']);

            $text = $language === 'ar'
                ? match ($transition) {
                    ConclusionTransition::Persisted => "ما زال نمط \"{$title}\" مدعومًا في التقرير الأحدث كما كان في التقرير الأقدم.",
                    ConclusionTransition::Disappeared => "النمط \"{$title}\" الذي كان مدعومًا في تقرير سابق لم يعد مدعومًا في التقرير الأحدث.",
                    ConclusionTransition::Appeared => "ظهر في التقرير الأحدث نمط \"{$title}\" لم يكن مدعومًا في التقرير الأقدم.",
                    ConclusionTransition::Transient => "ظهر نمط \"{$title}\" في تقرير وسيط بين التقارير المقارَنة، لكنه غير موجود في التقرير الأول ولا في التقرير الأحدث.",
                }
            : match ($transition) {
                ConclusionTransition::Persisted => "The pattern \"{$title}\" remains supported by the latest analysis, as it was by the earlier one.",
                ConclusionTransition::Disappeared => "The pattern \"{$title}\", previously supported by an earlier analysis, is no longer supported by the latest one.",
                ConclusionTransition::Appeared => "A pattern not previously supported, \"{$title}\", is newly supported by the latest analysis.",
                ConclusionTransition::Transient => "The pattern \"{$title}\" was supported by an analysis between the compared reports, but is present in neither the earliest nor the latest one.",
            };

            return ['conclusion_code' => $t['conclusion_code'], 'transition' => $t['transition'], 'text' => $text];
        }, $patternTransitions);
    }

    private function overallPicture(int $normalizedCount, int $betterCount, int $worseCount, string $language): string
    {
        $positiveCount = $normalizedCount + $betterCount;

        if ($language === 'ar') {
            if ($positiveCount === 0 && $worseCount === 0) {
                return 'لم تُظهر المقارنة تغيرًا مخبريًا مهمًا في المؤشرات غير الطبيعية القابلة للمقارنة.';
            }
            if ($worseCount === 0) {
                return 'أظهرت المقارنة اتجاهًا مخبريًا أفضل في المؤشرات التي تغيّرت، دون ظهور تغيرات جديدة أو أسوأ.';
            }
            if ($positiveCount === 0) {
                return 'أظهرت المقارنة تغيرات مخبرية جديدة أو أسوأ في بعض المؤشرات، دون تحسّن مخبري ملحوظ آخر.';
            }

            return 'تُظهر المقارنة تحرك بعض المؤشرات باتجاه المجال المرجعي، بينما ظهرت تغيرات جديدة في مؤشرات أخرى. لذلك لا تمثل المقارنة تحسنًا أو تراجعًا شاملًا، بل تغيرات في اتجاهات مختلفة.';
        }

        if ($positiveCount === 0 && $worseCount === 0) {
            return 'The comparison did not show a meaningful laboratory change in the comparable abnormal markers.';
        }
        if ($worseCount === 0) {
            return 'The comparison shows a better laboratory direction in the markers that changed, with no new or worsening changes.';
        }
        if ($positiveCount === 0) {
            return 'The comparison shows new or worsening laboratory changes in some markers, without another notable laboratory improvement.';
        }

        return 'The comparison shows some markers moving toward the reference range, while new changes appeared in others. It therefore does not represent an overall improvement or decline, but changes in different directions.';
    }

    /** @param array<int, array<string, mixed>> $resolvedGroups */
    private function interpretation(array $resolvedGroups, string $language): string
    {
        $sentences = array_filter(array_map(
            fn (array $group): string => (string) ($group['clinical_relevance'] ?? ''),
            $resolvedGroups,
        ));

        if ($sentences === []) {
            return '';
        }

        return implode(' ', array_values(array_unique($sentences)));
    }

    /** @param array<int, array<string, mixed>> $resolvedGroups */
    private function clinicalSignificance(array $resolvedGroups, string $language): string
    {
        $sentences = array_filter(array_map(
            fn (array $group): string => (string) ($group['patient_friendly_meaning'] ?? ''),
            $resolvedGroups,
        ));

        if ($sentences === []) {
            return '';
        }

        return implode(' ', array_values(array_unique($sentences)));
    }

    /** @param array<int, array<string, mixed>> $resolvedGroups
     * @return array<int, array{context_code:string,text:string}>
     */
    private function flattenGroupItems(array $resolvedGroups, string $dottedPath): array
    {
        [$outer, $inner] = explode('.', $dottedPath);
        $result = [];
        $seen = [];
        foreach ($resolvedGroups as $group) {
            $items = $group[$outer][$inner] ?? [];
            foreach ($items as $item) {
                if (isset($seen[$item['code']])) {
                    continue;
                }
                $seen[$item['code']] = true;
                $result[] = ['context_code' => $item['code'], 'text' => $item['text']];
            }
        }

        return $result;
    }

    private function unchangedSummary(int $count, string $language): string
    {
        if ($language === 'ar') {
            return $count > 0
                ? "بقيت {$count} مؤشرات أخرى قابلة للمقارنة دون تغير مهم."
                : 'لم تتبقَّ مؤشرات أخرى قابلة للمقارنة دون تغيّر.';
        }

        return $count > 0
            ? "{$count} other comparable markers showed no meaningful change."
            : 'No other comparable markers remained without a meaningful change.';
    }

    private function limitations(string $language): string
    {
        return $language === 'ar'
            ? 'هذه المقارنة تعليمية فقط ولا تمثل تشخيصًا طبيًا أو خطة علاج. لا يمكن من المقارنة المخبرية وحدها تأكيد تحسّن الأعراض أو زوال سبب الحالة، ويُرجى استشارة مختص طبي مؤهل لتفسير النتائج.'
            : 'This comparison is educational only and does not represent a medical diagnosis or a treatment plan. Laboratory comparison alone cannot confirm that symptoms improved or that the underlying cause resolved; consult a qualified healthcare professional for medical interpretation.';
    }

    /** @param array<string, mixed> $localizedText */
    private function localized(array $localizedText, string $language): string
    {
        $value = $language === 'ar' ? ($localizedText['ar'] ?? null) : ($localizedText['en'] ?? null);

        return is_string($value) && $value !== '' ? $value : (string) ($localizedText['en'] ?? '');
    }

    /** Same intent as localized(), for flat sibling fields (e.g. display_name/display_name_ar)
     * rather than a nested {en, ar} LocalizedText object. */
    private function localizedField(?string $english, ?string $arabic, string $language): ?string
    {
        if ($language === 'ar' && is_string($arabic) && $arabic !== '') {
            return $arabic;
        }

        return $english;
    }
}
