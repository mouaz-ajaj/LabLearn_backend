<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerifiedResultSetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_id' => $this->report_id,
            'version' => $this->version,
            'patient_age_years' => $this->patient_age_years,
            'patient_sex' => $this->patient_sex->value,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'excluded_source_result_ids' => $this->excluded_source_result_ids ?? [],
            'category_gate' => [
                'status' => $this->category_gate_status,
                'category' => $this->category_gate_category,
                'evidence' => $this->category_gate_evidence,
            ],
            'rows' => VerifiedResultResource::collection($this->whenLoaded('results')),
        ];
    }
}
