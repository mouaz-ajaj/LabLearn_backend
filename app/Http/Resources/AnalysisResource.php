<?php

namespace App\Http\Resources;

use App\Enums\AnalysisStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $succeeded = $this->status === AnalysisStatus::Succeeded;

        return [
            'id' => $this->getKey(),
            'report_id' => $this->report_id,
            'verified_result_set_id' => $this->verified_result_set_id,
            'verified_result_set_version' => $this->verified_result_set_version,
            'report_category' => $this->report_category,
            'status' => $this->status->value,
            'flow' => $this->flow->value,
            'versions' => [
                'schema' => (string) $this->schema_version,
                'input_schema' => (string) $this->input_schema_version,
                'engine' => $this->engine_version,
                'ruleset' => $this->ruleset_version,
                'analyte_catalog' => $this->catalog_version,
            ],
            'timing' => [
                'queued_at' => $this->created_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'completed_at' => $this->completed_at?->toIso8601String(),
                'duration_ms' => $this->duration_ms,
                'attempt_count' => $this->attempt_count,
            ],
            'error' => $this->status === AnalysisStatus::Failed ? [
                'code' => $this->error_code,
                'message' => $this->safe_error_message,
                'retryable' => in_array($this->error_code, ['KBS_TIMEOUT', 'KBS_SERVICE_UNAVAILABLE', 'KBS_ANALYSIS_FAILED'], true),
            ] : null,
            'result' => $succeeded ? [
                'summary' => $this->summary_json,
                'conclusions' => $this->whenLoaded('conclusions', fn () => $this->conclusions->map(fn ($item): array => [
                    'code' => $item->conclusion_code,
                    'level' => $item->level,
                    'title' => $item->title_json,
                    'summary' => $item->summary_json,
                    'evidence' => $item->evidence_json,
                    'rule_codes' => $item->rule_codes_json,
                ])->values()->all()),
                'normalized_results' => $this->normalized_results_json ?? [],
                'facts' => $this->facts_json ?? [],
                'missing_information' => $this->missing_information_json ?? [],
                'warnings' => $this->warnings_json ?? [],
                'rule_traces' => $this->whenLoaded('ruleTraces', fn () => $this->ruleTraces->map(fn ($trace): array => [
                    'rule_code' => $trace->rule_code,
                    'rule_version' => $trace->rule_version,
                    'fired' => $trace->fired,
                    'conditions' => $trace->conditions_json,
                    'evidence' => $trace->evidence_json,
                    'conclusion_codes' => $trace->conclusion_codes_json,
                ])->values()->all()),
                'verified_results' => $this->whenLoaded('verifiedResultSet', fn () => $this->verifiedResultSet->results->map(fn ($row): array => [
                    'id' => $row->getKey(),
                    'label' => $row->label,
                    'value' => $row->value,
                    'unit' => $row->unit,
                    'reference_range' => $row->reference_range,
                    'was_modified' => $row->was_modified,
                    'was_added_manually' => $row->was_added_manually,
                ])->values()->all()),
                'disclaimer' => $this->disclaimer_json,
            ] : null,
        ];
    }
}
