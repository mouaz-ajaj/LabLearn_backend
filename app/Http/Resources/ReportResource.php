<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'test_category' => $this->test_category->value,
            'source_type' => $this->source_type->value,
            'report_date' => $this->report_date?->toDateString(),
            'patient_age_years' => $this->patient_age_years,
            'patient_sex' => $this->patient_sex?->value,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
