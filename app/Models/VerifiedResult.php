<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'verified_result_set_id', 'source_extracted_result_id', 'label', 'value', 'unit',
    'reference_range', 'canonical_analyte_id_hint', 'page', 'original_confidence',
    'was_added_manually', 'was_modified', 'display_order',
])]
class VerifiedResult extends Model
{
    public function resultSet(): BelongsTo
    {
        return $this->belongsTo(VerifiedResultSet::class, 'verified_result_set_id');
    }

    public function sourceExtractedResult(): BelongsTo
    {
        return $this->belongsTo(ExtractedResult::class, 'source_extracted_result_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'original_confidence' => 'float',
            'was_added_manually' => 'boolean',
            'was_modified' => 'boolean',
            'display_order' => 'integer',
        ];
    }
}
