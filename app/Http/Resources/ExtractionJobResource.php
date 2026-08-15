<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExtractionJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_id' => $this->report_id,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'current_step' => $this->current_step,
            'attempts' => $this->attempts,
            'error_code' => $this->error_code,
            'safe_error_message' => $this->safe_error_message,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
        ];
    }
}
