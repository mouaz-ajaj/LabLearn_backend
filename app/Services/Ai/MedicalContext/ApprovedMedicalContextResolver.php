<?php

namespace App\Services\Ai\MedicalContext;

use App\Models\Analysis;

/**
 * Deterministic bridge between an Analysis's own already-computed KBS
 * conclusions and the reviewed ApprovedMedicalContextCatalog. This is the ONLY
 * place that decides which approved medical context applies to a given
 * Analysis - Gemini never selects context on its own, and the response
 * validator uses this same resolver's allow-list output to reject anything
 * Gemini didn't receive.
 */
class ApprovedMedicalContextResolver
{
    public function __construct(private readonly ApprovedMedicalContextCatalog $catalog) {}

    /** @return array<int, array<string, mixed>> resolved, supersedes-filtered approved groups for this analysis */
    public function resolveGroups(Analysis $analysis): array
    {
        $conclusionCodes = $analysis->conclusions->pluck('conclusion_code')->unique()->values()->all();

        return $this->catalog->groupsForConclusionCodes($conclusionCodes);
    }

    /**
     * Builds the localized `allowed_medical_context` payload sent to Gemini:
     * every approved group's causes/symptoms/next_steps/red_flags/student
     * context, each carrying its stable code and the text for the requested
     * language only (Laravel picks the language here, same discipline as
     * every other AI context builder in this codebase - Gemini is never asked
     * to pick between `ar`/`en` itself).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildLocalizedContext(Analysis $analysis, string $language): array
    {
        return $this->catalog->localizeGroups($this->resolveGroups($analysis), $language);
    }

    /**
     * Flattened sets of every code Gemini was actually given, one set per
     * output field type. The response validator allow-lists against exactly
     * these sets - a code that was not sent for THIS analysis can never pass
     * validation, regardless of whether it exists somewhere else in the
     * catalog.
     *
     * @return array{causes: string[], symptoms: string[], next_steps: string[], red_flags: string[], differential: string[], distinguishing: string[]}
     */
    public function allowedCodes(Analysis $analysis): array
    {
        $codes = ['causes' => [], 'symptoms' => [], 'next_steps' => [], 'red_flags' => [], 'differential' => [], 'distinguishing' => []];
        foreach ($this->resolveGroups($analysis) as $group) {
            foreach ($group['possible_causes'] ?? [] as $item) {
                $codes['causes'][] = $item['code'];
            }
            foreach ($group['common_symptoms'] ?? [] as $item) {
                $codes['symptoms'][] = $item['code'];
            }
            foreach ($group['general_next_steps'] ?? [] as $item) {
                $codes['next_steps'][] = $item['code'];
            }
            foreach ($group['red_flags'] ?? [] as $item) {
                $codes['red_flags'][] = $item['code'];
            }
            $studentContext = $group['student_context'] ?? [];
            foreach ($studentContext['differential_considerations'] ?? [] as $item) {
                $codes['differential'][] = $item['code'];
            }
            foreach ($studentContext['distinguishing_information'] ?? [] as $item) {
                $codes['distinguishing'][] = $item['code'];
            }
        }
        foreach ($codes as $type => $list) {
            $codes[$type] = array_values(array_unique($list));
        }

        return $codes;
    }
}
