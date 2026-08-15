<?php

namespace App\Models;

use Database\Factories\ExtractedResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'extraction_job_id',
    'report_id',
    'raw_label',
    'raw_value',
    'raw_unit',
    'raw_reference',
    'ocr_canonical_name',
    'ocr_test_code',
    'ocr_kbs_test_id',
    'ocr_normalized_value',
    'ocr_normalized_unit',
    'ocr_normalized_reference',
    'page',
    'bbox_json',
    'confidence',
    'raw_row_json',
])]
class ExtractedResult extends Model
{
    /** @use HasFactory<ExtractedResultFactory> */
    use HasFactory;

    public function extractionJob(): BelongsTo
    {
        return $this->belongsTo(ExtractionJob::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'page' => 'integer',
            'bbox_json' => 'array',
            'confidence' => 'float',
            'raw_row_json' => 'array',
        ];
    }
}
