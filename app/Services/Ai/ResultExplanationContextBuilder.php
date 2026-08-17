<?php

namespace App\Services\Ai;

use App\Models\Analysis;
use App\Services\Ai\MedicalContext\ApprovedMedicalContextResolver;

/**
 * Builds the minimal, privacy-safe payload sent to Gemini from an already-succeeded
 * deterministic Analysis. Mirrors ComparisonContextBuilder's data-minimization
 * discipline: never includes patient/user identity, account email, tokens, report
 * files/images, raw OCR text, filenames, internal storage paths, idempotency keys, or
 * unrelated quiz data - only the Analysis's own already-computed conclusions,
 * fired rule codes, normalized (KBS-structured) analyte results, and missing-
 * information warnings, all keyed by stable identifiers Gemini may reference back.
 *
 * `allowed_medical_context` (2026-08-17 content redesign) is resolved entirely by
 * ApprovedMedicalContextResolver from the analysis's own conclusion codes against the
 * reviewed catalog in resources/medical_context/*.json - Gemini receives this, it
 * never invents it. Both Regular and Student roles receive the exact same resolved
 * context; ResultExplanationPromptBuilder's role-specific instructions and
 * responseSchema() (which omits student_context entirely for role=regular) are what
 * differentiate the two outputs, not two different context payloads.
 */
class ResultExplanationContextBuilder
{
    public function __construct(private readonly ApprovedMedicalContextResolver $medicalContextResolver) {}

    /** @return array<string, mixed> */
    public function build(Analysis $analysis, string $role, string $language): array
    {
        $conclusions = $analysis->conclusions->map(fn ($conclusion): array => [
            'code' => $conclusion->conclusion_code,
            'level' => $conclusion->level,
            'title' => $this->localized($conclusion->title_json, $language),
            'summary' => $this->localized($conclusion->summary_json, $language),
            'evidence' => collect($conclusion->evidence_json)->map(fn (array $item): array => [
                'analyte_id' => $item['analyte_id'] ?? null,
                'label' => $this->localizedField($item['label'] ?? null, $item['label_ar'] ?? null, $language),
                'value' => $item['value'] ?? null,
                'unit' => $item['unit'] ?? null,
                'status' => $item['status'] ?? null,
            ])->values()->all(),
            'rule_codes' => $conclusion->rule_codes_json,
        ])->values()->all();

        $firedRuleCodes = $analysis->ruleTraces
            ->filter(fn ($trace): bool => (bool) $trace->fired)
            ->pluck('rule_code')
            ->unique()
            ->values()
            ->all();

        $missingInformation = collect($analysis->missing_information_json ?? [])
            ->map(fn (array $item): array => [
                'code' => $item['code'] ?? null,
                'analyte_id' => $item['analyte_id'] ?? null,
                'message' => $this->localized($item['message'] ?? [], $language),
            ])
            ->values()
            ->all();

        // Only KBS's own structured, normalized analyte output is sent - never the
        // free-text verified value/reference-range rows, which are not safely
        // machine-parseable (same rule Phase 4C's comparison core already follows).
        $analytes = collect($analysis->normalized_results_json ?? [])
            ->map(fn (array $row): array => [
                'analyte_id' => $row['analyte_id'] ?? null,
                'display_name' => $this->localizedField($row['display_name'] ?? null, $row['display_name_ar'] ?? null, $language),
                'value' => $row['value'] ?? null,
                'unit' => $row['unit'] ?? null,
                'status' => $row['status'] ?? null,
                'reference_range' => $row['reference_range'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'task' => 'result_contextualization',
            'language' => $language,
            'user_role' => $role,
            'category' => $analysis->report_category,
            'analysis' => [
                'summary' => $this->localized($analysis->summary_json ?? [], $language),
                'conclusions' => $conclusions,
                'fired_rule_codes' => $firedRuleCodes,
                'missing_information' => $missingInformation,
            ],
            'verified_or_normalized_analytes' => $analytes,
            // Resolved deterministically from this analysis's own conclusion codes
            // against the reviewed catalog - see class docblock. Empty when no
            // conclusion has approved coverage yet (a real, honestly-reported gap,
            // never backfilled by asking Gemini to fill it from its own knowledge).
            'allowed_medical_context' => [
                'groups' => $this->medicalContextResolver->buildLocalizedContext($analysis, $language),
            ],
        ];
    }

    /** @return string[] every conclusion_code present in this analysis, for the response validator's allow-list */
    public function allowedConclusionCodes(Analysis $analysis): array
    {
        return $analysis->conclusions->pluck('conclusion_code')->values()->all();
    }

    /**
     * Every approved medical-context code Gemini was actually given for this
     * analysis, one array per output field type - see
     * ApprovedMedicalContextResolver::allowedCodes() for the exact resolution
     * logic. The response validator rejects any cause/symptom/next-step/red-flag/
     * differential/distinguishing-information code not present here.
     *
     * @return array{causes: string[], symptoms: string[], next_steps: string[], red_flags: string[], differential: string[], distinguishing: string[]}
     */
    public function allowedMedicalContextCodes(Analysis $analysis): array
    {
        return $this->medicalContextResolver->allowedCodes($analysis);
    }

    /** @return string[] every analyte_id present in this analysis's normalized results, for the response validator's allow-list */
    public function allowedAnalyteIds(Analysis $analysis): array
    {
        return collect($analysis->normalized_results_json ?? [])
            ->pluck('analyte_id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @return string[] every fired rule code in this analysis, for the response validator's allow-list */
    public function allowedRuleCodes(Analysis $analysis): array
    {
        return $analysis->ruleTraces
            ->filter(fn ($trace): bool => (bool) $trace->fired)
            ->pluck('rule_code')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $localizedText
     *
     * Since the KBS localization repair, every conclusion title/summary and
     * missing_information message KBS sends for an active rule/condition carries a
     * genuine Arabic value - this English fallback is now an exceptional path (a
     * not-yet-translated rule, or a legacy analysis persisted before the repair),
     * not the routine case it used to be. It intentionally still falls back to
     * English rather than returning an empty string: an English sentence inside an
     * Arabic explanation card is a smaller failure than a blank card, and the
     * language-aware response validator (see ResultExplanationResponseValidator)
     * is what ultimately guards against Gemini echoing this English text back
     * verbatim in an "ar" response.
     */
    private function localized(array $localizedText, string $language): string
    {
        $value = $language === 'ar' ? ($localizedText['ar'] ?? null) : ($localizedText['en'] ?? null);

        return is_string($value) && $value !== '' ? $value : (string) ($localizedText['en'] ?? '');
    }

    /** Same intent as localized(), for flat sibling fields (e.g. display_name/display_name_ar,
     * label/label_ar) rather than a nested {en, ar} LocalizedText object. */
    private function localizedField(?string $english, ?string $arabic, string $language): ?string
    {
        if ($language === 'ar' && is_string($arabic) && $arabic !== '') {
            return $arabic;
        }

        return $english;
    }
}
