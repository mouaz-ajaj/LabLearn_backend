<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\ReportFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportFile> */
class ReportFileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'original_name' => 'report.png',
            'storage_disk' => 'local',
            'storage_path' => 'reports/example/report.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 1024,
            'checksum' => hash('sha256', 'report'),
        ];
    }
}
