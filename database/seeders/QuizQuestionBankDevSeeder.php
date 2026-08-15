<?php

namespace Database\Seeders;

use App\Enums\QuestionReviewStatus;
use App\Enums\QuizQuestionCategory;
use App\Enums\ReportTestCategory;
use App\Models\Question;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT / TEST FIXTURE ONLY.
 *
 * These rows are NOT medically reviewed production content. They exist only so a
 * developer running the app locally can exercise POST /reports/{report}/quiz end to
 * end without an empty Question Bank. Populating the real, medically reviewed General
 * Question Bank (target ~14 questions per category: CBC, DIABETES, LIVER_FUNCTION) is a
 * separate content/review task, not something this seeder or any coding agent should
 * silently fabricate. See backend/docs/phase-3b-quiz.md.
 *
 * Only ever runs locally, gated the same way DatabaseSeeder gates demo users.
 */
class QuizQuestionBankDevSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        if (Question::query()->exists()) {
            return;
        }

        foreach (ReportTestCategory::cases() as $category) {
            $this->seedCategory($category);
        }
    }

    private function seedCategory(ReportTestCategory $category): void
    {
        $fixtures = [
            [
                'en' => '[DEV FIXTURE] Which unit is commonly used to report hemoglobin concentration?',
                'ar' => '[بيانات تجريبية] ما الوحدة الشائعة للتعبير عن تركيز الهيموغلوبين؟',
            ],
            [
                'en' => '[DEV FIXTURE] A result flagged "LOW" means the value fell below which reference?',
                'ar' => '[بيانات تجريبية] ماذا تعني علامة "منخفض" على القيمة المخبرية؟',
            ],
            [
                'en' => '[DEV FIXTURE] Reference ranges are typically provided by which source?',
                'ar' => '[بيانات تجريبية] من أين تُستمد المجالات المرجعية عادة؟',
            ],
            [
                'en' => '[DEV FIXTURE] Why does LabLearn ask you to verify OCR-extracted values before analysis?',
                'ar' => '[بيانات تجريبية] لماذا يطلب التطبيق مراجعة القيم المستخرجة قبل التحليل؟',
            ],
            [
                'en' => '[DEV FIXTURE] Is a single abnormal lab value enough for a confirmed diagnosis?',
                'ar' => '[بيانات تجريبية] هل تكفي قيمة مخبرية واحدة غير طبيعية لتأكيد تشخيص؟',
            ],
        ];

        foreach ($fixtures as $text) {
            Question::query()->create([
                'category' => $category,
                'type' => QuizQuestionCategory::General,
                'question_text_json' => $text,
                'options_json' => [
                    ['id' => 'a', 'text' => ['en' => '[Dev fixture option A]', 'ar' => '[خيار تجريبي أ]']],
                    ['id' => 'b', 'text' => ['en' => '[Dev fixture option B]', 'ar' => '[خيار تجريبي ب]']],
                    ['id' => 'c', 'text' => ['en' => '[Dev fixture option C]', 'ar' => '[خيار تجريبي ج]']],
                    ['id' => 'd', 'text' => ['en' => '[Dev fixture option D]', 'ar' => '[خيار تجريبي د]']],
                ],
                'correct_option_id' => 'a',
                'explanation_json' => [
                    'en' => '[DEV FIXTURE] This is placeholder explanation text for local testing only, not reviewed medical content.',
                    'ar' => '[بيانات تجريبية] نص شرح مؤقت لأغراض الاختبار المحلي فقط، وليس محتوى طبيًا مراجعًا.',
                ],
                'active' => true,
                'content_version' => 1,
                'review_status' => QuestionReviewStatus::Draft,
            ]);
        }
    }
}
