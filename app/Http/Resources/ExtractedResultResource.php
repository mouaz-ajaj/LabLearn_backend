<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExtractedResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'raw_label' => $this->raw_label,
            'raw_value' => $this->raw_value,
            'raw_unit' => $this->raw_unit,
            'raw_reference' => $this->raw_reference,
            'ocr_interpretation' => [
                'canonical_name' => $this->ocr_canonical_name,
                'test_code' => $this->ocr_test_code,
                'kbs_test_id' => $this->ocr_kbs_test_id,
                'normalized_value' => $this->ocr_normalized_value,
                'normalized_unit' => $this->ocr_normalized_unit,
                'normalized_reference' => $this->ocr_normalized_reference,
            ],
            'page' => $this->page,
            'bbox' => $this->bbox_json,
            'confidence' => $this->confidence,
            'raw_row' => $this->raw_row_json,
        ];
    }
}
