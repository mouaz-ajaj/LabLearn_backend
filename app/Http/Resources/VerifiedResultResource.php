<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerifiedResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_extracted_result_id' => $this->source_extracted_result_id,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'reference_range' => $this->reference_range,
            'page' => $this->page,
            'original_confidence' => $this->original_confidence,
            'was_added_manually' => $this->was_added_manually,
            'was_modified' => $this->was_modified,
            'display_order' => $this->display_order,
        ];
    }
}
