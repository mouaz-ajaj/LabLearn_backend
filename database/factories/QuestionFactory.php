<?php

namespace Database\Factories;

use App\Enums\QuestionReviewStatus;
use App\Enums\QuizQuestionCategory;
use App\Enums\ReportTestCategory;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Test/factory content only - never medically reviewed, never seeded outside of the
 * local/test environment. See database/seeders/QuizQuestionBankDevSeeder.php.
 *
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $optionIds = ['a', 'b', 'c', 'd'];
        $correct = $optionIds[array_rand($optionIds)];

        return [
            'category' => ReportTestCategory::Cbc,
            'type' => QuizQuestionCategory::General,
            'question_text_json' => [
                'en' => 'Test fixture question '.Str::random(6),
                'ar' => 'سؤال تجريبي '.Str::random(6),
            ],
            'options_json' => collect($optionIds)->map(fn (string $id): array => [
                'id' => $id,
                'text' => ['en' => 'Option '.strtoupper($id), 'ar' => 'خيار '.strtoupper($id)],
            ])->all(),
            'correct_option_id' => $correct,
            'explanation_json' => ['en' => 'Test fixture explanation.', 'ar' => 'شرح تجريبي.'],
            'active' => true,
            'content_version' => 1,
            'review_status' => QuestionReviewStatus::Draft,
        ];
    }

    public function forCategory(ReportTestCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
