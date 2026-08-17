<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight report-history list item. Deliberately excludes OCR payloads,
 * verified values, KBS rule traces, and report files - those belong to the
 * Phase 4B report-details endpoint, not the history list.
 */
class ReportHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'test_category' => $this->test_category->value,
            'source_type' => $this->source_type->value,
            'status' => $this->status->value,
            'report_date' => $this->report_date?->toDateString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
