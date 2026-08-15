<?php

namespace Database\Factories;

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\ReportTestCategory;
use App\Models\Analysis;
use App\Models\Report;
use App\Models\User;
use App\Models\VerifiedResultSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Analysis> */
class AnalysisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'verified_result_set_id' => fn (array $attributes) => VerifiedResultSet::query()->create([
                'report_id' => $attributes['report_id'],
                'version' => 1,
                'confirmed_by_user_id' => User::factory(),
                'patient_age_years' => 30,
                'patient_sex' => 'FEMALE',
                'idempotency_key' => fake()->uuid(),
                'category_gate_status' => 'MATCH',
                'category_gate_category' => ReportTestCategory::Cbc->value,
                'confirmed_at' => now(),
            ])->getKey(),
            'verified_result_set_version' => 1,
            'user_id' => User::factory(),
            'report_category' => ReportTestCategory::Cbc->value,
            'status' => AnalysisStatus::Queued,
            'flow' => AnalysisFlow::DirectResult,
            'identity_key' => hash('sha256', fake()->uuid()),
            'ruleset_version' => 'test-ruleset',
        ];
    }
}
