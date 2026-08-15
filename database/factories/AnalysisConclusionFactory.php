<?php

namespace Database\Factories;

use App\Models\Analysis;
use App\Models\AnalysisConclusion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnalysisConclusion> */
class AnalysisConclusionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'conclusion_code' => fake()->unique()->slug(2),
            'level' => 'educational_finding',
            'title_json' => ['en' => fake()->sentence(3)],
            'summary_json' => ['en' => fake()->sentence()],
            'evidence_json' => [],
            'rule_codes_json' => [],
            'display_order' => 1,
        ];
    }
}
