<?php

namespace Database\Factories;

use App\Models\ExtractedResult;
use App\Models\ExtractionJob;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExtractedResult> */
class ExtractedResultFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'extraction_job_id' => ExtractionJob::factory(),
            'report_id' => Report::factory(),
            'raw_label' => 'Hemoglobin',
            'raw_value' => '13.5',
            'raw_unit' => 'g/dL',
            'raw_reference' => '12.0 - 16.0',
            'page' => 1,
            'bbox_json' => null,
            'confidence' => 0.95,
            'raw_row_json' => [],
        ];
    }
}
