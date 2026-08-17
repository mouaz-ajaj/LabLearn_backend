<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Ai\MedicalContext\ComparisonMedicalContextResolver;
use App\Services\Comparison\GroupAnalyteChanges;

/**
 * Builds the minimal, privacy-safe, role-aware payload sent to Gemini from an
 * already-computed deterministic comparison. Never includes: patient/user identity,
 * account email, tokens, report files/images, raw OCR text, filenames, or database IDs
 * beyond the report sequence number already visible to the user.
 *
 * 2026-08-17 redesign: sends Laravel-precomputed SECTIONS (normalized / better-but-
 * still-abnormal / new-or-worse / persistent-abnormal / pattern transitions) instead
 * of a flat per-analyte list and a flat per-report KBS conclusion dump - Gemini only
 * ever explains section membership Laravel already decided, it never sorts an analyte
 * into a section itself. This is also what fixes the historical repetition/confusion
 * bug: each analyte/pattern now appears in exactly one section instead of being
 * re-explained once per report and once per KBS conclusion.
 */
class ComparisonContextBuilder
{
    public function __construct(
        private readonly GroupAnalyteChanges $groupAnalyteChanges,
        private readonly ComparisonMedicalContextResolver $medicalContextResolver,
    ) {}

    /** @param  array<string, mixed>  $comparison  the array returned by BuildReportComparison
     * @return array<string, mixed>
     */
    public function build(array $comparison, User $user, string $language): array
    {
        $role = $this->role($user);
        $grouped = $this->groupAnalyteChanges->handle($comparison['analytes']);

        $reports = array_map(fn (array $report): array => [
            'sequence' => $report['sequence'],
            'date' => $report['date'],
        ], $comparison['reports']);

        $patternTransitions = collect($comparison['pattern_transitions'])
            ->map(fn (array $t): array => [
                'conclusion_code' => $t['conclusion_code'],
                'transition' => $t['transition'],
                'title' => $this->localized($t['title'], $language),
                'occurrence_count' => $t['occurrence_count'],
                'present_in_latest' => $t['present_in_latest'],
            ])
            ->values()
            ->all();

        return [
            'task' => 'comparison_contextualization',
            'language' => $language,
            'role' => $role,
            'category' => $comparison['category'],
            'reports' => $reports,
            'normalized_findings' => $this->localizeFindings($grouped['normalized'], $language),
            'better_but_still_abnormal' => $this->localizeFindings($grouped['better_but_still_abnormal'], $language),
            'new_or_worse_findings' => $this->localizeFindings($grouped['new_or_worse'], $language),
            'persistent_abnormalities' => $this->localizeFindings($grouped['persistent_abnormal'], $language),
            'unchanged_comparable_count' => $grouped['unchanged_comparable_count'],
            'pattern_transitions' => $patternTransitions,
            // Reused, not reinvented: the exact same reviewed, source-grounded Phase 4E
            // catalog (see docs/phase-4e-result-explanation.md), scoped here to only the
            // APPEARED/PERSISTED conclusion codes relevant to explaining a CHANGE.
            'allowed_medical_context' => ['groups' => $this->medicalContextResolver->buildLocalizedContext($comparison['pattern_transitions'], $language)],
        ];
    }

    private function role(User $user): string
    {
        return $user->role->value === 'student' ? 'student' : 'regular';
    }

    /** @param array<int, array<string, mixed>> $findings
     * @return array<int, array<string, mixed>>
     */
    private function localizeFindings(array $findings, string $language): array
    {
        return array_map(fn (array $f): array => [
            'analyte_id' => $f['analyte_id'],
            'display_name' => $this->localizedField($f['display_name'] ?? null, $f['display_name_ar'] ?? null, $language),
            'unit' => $f['unit'],
            'earliest_value' => $f['earliest_value'],
            'latest_value' => $f['latest_value'],
            'earliest_status' => $f['earliest_status'],
            'latest_status' => $f['latest_status'],
        ], $findings);
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

    /** @param  array<string, mixed>  $comparison
     * @return array{normalized: string[], better_but_still_abnormal: string[], new_or_worse: string[], persistent_abnormal: string[]}
     */
    public function allowedAnalyteIdsBySection(array $comparison): array
    {
        $grouped = $this->groupAnalyteChanges->handle($comparison['analytes']);

        return [
            'normalized' => array_column($grouped['normalized'], 'analyte_id'),
            'better_but_still_abnormal' => array_column($grouped['better_but_still_abnormal'], 'analyte_id'),
            'new_or_worse' => array_column($grouped['new_or_worse'], 'analyte_id'),
            'persistent_abnormal' => array_column($grouped['persistent_abnormal'], 'analyte_id'),
        ];
    }

    /** @param  array<string, mixed>  $comparison
     * @return array<string, string> conclusion_code => transition, for the response validator
     */
    public function allowedPatternTransitions(array $comparison): array
    {
        return collect($comparison['pattern_transitions'])
            ->pluck('transition', 'conclusion_code')
            ->all();
    }

    /** @param  array<string, mixed>  $comparison
     * @return array{differential: string[], interpretation_clues: string[]}
     */
    public function allowedMedicalContextCodes(array $comparison): array
    {
        return $this->medicalContextResolver->allowedCodes($comparison['pattern_transitions']);
    }
}
