<?php

namespace Database\Factories;

use App\Enums\ExtractionJobStatus;
use App\Models\ExtractionJob;
use App\Models\Report;
use App\Models\ReportFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExtractionJob> */
class ExtractionJobFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'report_file_id' => ReportFile::factory(),
            'status' => ExtractionJobStatus::Queued,
            'progress' => 0,
            'current_step' => 'QUEUED',
            'attempts' => 0,
            'engine_name' => 'medical-laboratory-ocr-api',
        ];
    }
}
