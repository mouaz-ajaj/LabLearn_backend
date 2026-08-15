<?php

namespace App\Services\Kbs;

use App\Models\Analysis;
use App\Models\VerifiedResultSet;
use Illuminate\Support\Str;

class KbsRequestMapper
{
    /** @return array<string, mixed> */
    public function map(Analysis $analysis): array
    {
        $set = $analysis->verifiedResultSet()->with('results')->firstOrFail();
        $payload = $this->build($analysis->report_category, $set);
        $payload['verified_result_set']['version'] = (int) $analysis->verified_result_set_version;

        return $payload;
    }

    /**
     * Build the same request shape before an Analysis row exists yet, for preflight
     * validation (KbsClient::validate()) ahead of ever dispatching the analysis job.
     *
     * @return array<string, mixed>
     */
    public function mapForPreflight(string $reportCategory, VerifiedResultSet $set): array
    {
        return $this->build($reportCategory, $set->loadMissing('results'));
    }

    /** @return array<string, mixed> */
    private function build(string $reportCategory, VerifiedResultSet $set): array
    {
        return [
            'request_id' => (string) Str::uuid(),
            'schema_version' => '1',
            'report_category' => $reportCategory,
            'verified_result_set' => [
                'id' => $set->getKey(),
                'version' => (int) $set->version,
            ],
            'patient_context' => [
                'age' => (float) $set->patient_age_years,
                'sex' => strtolower($set->patient_sex->value),
                'fasting' => null,
            ],
            'results' => $set->results->map(fn ($row): array => [
                'source_id' => $row->getKey(),
                'label' => $row->label,
                'value' => $row->value,
                'unit' => $row->unit,
                'reference' => $row->reference_range,
                // An optional candidate only -- carried forward from OCR's own resolved
                // identity when the verified label is unchanged (see
                // VerifyReport::canonicalAnalyteHintFor()). KBS independently validates
                // it (existence, category, unit compatibility, ambiguity rules) and
                // never trusts it blindly; the verified label/value/unit above remain
                // the authoritative record regardless of what the hint says.
                'analyte_id_hint' => $row->canonical_analyte_id_hint,
            ])->values()->all(),
        ];
    }
}
