<?php

namespace Database\Factories;

use App\Enums\ReportSourceType;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'test_category' => ReportTestCategory::Cbc,
            'source_type' => ReportSourceType::Image,
            'status' => ReportStatus::Uploaded,
            'report_date' => null,
        ];
    }

    public function forCategory(ReportTestCategory $category): static
    {
        return $this->state(fn (): array => [
            'test_category' => $category,
        ]);
    }
}
