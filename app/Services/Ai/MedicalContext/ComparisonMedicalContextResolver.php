<?php

namespace App\Services\Ai\MedicalContext;

/**
 * Comparison-specific adapter over the Phase 4E ApprovedMedicalContextCatalog - reuses
 * the exact same reviewed, source-grounded catalog (no second medical knowledge base),
 * but scopes it to what is actually relevant to a CHANGE explanation rather than the
 * full per-Analysis context Phase 4E resolves.
 *
 * Only conclusion codes that are APPEARED or PERSISTED (see ClassifyConclusionTransitions)
 * are resolved - a DISAPPEARED pattern gets no medical-context lookup at all, because
 * the product requirement here is to describe the expert-system transition itself
 * ("no longer supported"), never to explain the medical meaning of an absence, which
 * would risk implying a confirmed resolution the deterministic system never asserted.
 */
class ComparisonMedicalContextResolver
{
    public function __construct(private readonly ApprovedMedicalContextCatalog $catalog) {}

    /** @param  array<int, array<string, mixed>>  $patternTransitions  BuildReportComparison's comparison['pattern_transitions']
     * @return array<int, array<string, mixed>>
     */
    public function resolveGroups(array $patternTransitions): array
    {
        $relevantCodes = collect($patternTransitions)
            ->whereIn('transition', ['APPEARED', 'PERSISTED'])
            ->pluck('conclusion_code')
            ->unique()
            ->values()
            ->all();

        return $this->catalog->groupsForConclusionCodes($relevantCodes);
    }

    /** @param  array<int, array<string, mixed>>  $patternTransitions
     * @return array<int, array<string, mixed>>
     */
    public function buildLocalizedContext(array $patternTransitions, string $language): array
    {
        return $this->catalog->localizeGroups($this->resolveGroups($patternTransitions), $language);
    }

    /**
     * Flattened code sets the comparison response validator allow-lists against.
     * Comparison only ever surfaces differential considerations and distinguishing/
     * interpretation information for Student - it does not repeat Phase 4E's
     * causes/symptoms/next-steps/red-flags fields, since a comparison explains a
     * CHANGE, not a finding from scratch (see docs/phase-4c-comparison.md).
     *
     * @param  array<int, array<string, mixed>>  $patternTransitions
     * @return array{differential: string[], interpretation_clues: string[]}
     */
    public function allowedCodes(array $patternTransitions): array
    {
        $codes = ['differential' => [], 'interpretation_clues' => []];
        foreach ($this->resolveGroups($patternTransitions) as $group) {
            $studentContext = $group['student_context'] ?? [];
            foreach ($studentContext['differential_considerations'] ?? [] as $item) {
                $codes['differential'][] = $item['code'];
            }
            foreach ($studentContext['distinguishing_information'] ?? [] as $item) {
                $codes['interpretation_clues'][] = $item['code'];
            }
        }
        foreach ($codes as $type => $list) {
            $codes[$type] = array_values(array_unique($list));
        }

        return $codes;
    }
}
