<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Verified-value row for the read-only Phase 4B historical view. Deliberately
 * excludes source_extracted_result_id, page, and original_confidence - those are
 * OCR-review internals (see VerifiedResultResource), not part of what a user
 * confirmed and analyzed.
 */
class HistoricalVerifiedResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'reference_range' => $this->reference_range,
            'was_added_manually' => $this->was_added_manually,
            'was_modified' => $this->was_modified,
            'display_order' => $this->display_order,
        ];
    }
}
