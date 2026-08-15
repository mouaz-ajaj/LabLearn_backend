<?php

namespace App\Console\Commands;

use App\Enums\QuestionReviewStatus;
use App\Enums\QuizQuestionCategory;
use App\Enums\ReportTestCategory;
use App\Models\Question;
use App\Models\QuizQuestionSnapshot;
use App\Models\QuizSession;
use App\Models\StudentAnswer;
use App\Services\Quiz\GeneralQuestions\GeneralQuestionGenerator;
use App\Services\Quiz\GeneralQuestions\GeneralQuestionValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Rebuilds the KBS-driven General Question Bank from the CURRENT knowledge_base JSON
 * files + template catalog + generator_version — see
 * backend/docs/general-question-bank.md for the full design.
 *
 * Fail-safe by construction: generation and validation both happen entirely in PHP
 * memory BEFORE any database write. If either fails, nothing in `questions` changes
 * and the command exits non-zero. The actual replacement (delete stale
 * source=KBS_GENERATED rows + known [DEV FIXTURE] rows, insert the new bank) happens
 * inside one DB transaction, so a failure partway through rolls back to the previous,
 * still-working bank rather than leaving a partial one. `quiz_sessions`,
 * `quiz_question_snapshots`, and `student_answers` are never touched — historical
 * quiz attempts read from their own immutable snapshot, never from `questions`.
 */
class RefreshGeneralQuestionBank extends Command
{
    protected $signature = 'quiz:refresh-general-bank {--force : Run even if QUIZ_REFRESH_GENERAL_BANK_ON_START is not enabled}';

    protected $description = 'Regenerate the KBS-driven General Question Bank from the current knowledge_base JSON and template catalog';

    public function handle(): int
    {
        if (! $this->option('force') && ! (bool) config('quiz.refresh_general_bank_on_start')) {
            $this->info('Skipped: QUIZ_REFRESH_GENERAL_BANK_ON_START is not enabled and --force was not passed.');
            $this->line('This is expected in a production-configured environment — question content is not silently rebuilt on every run.');

            return self::SUCCESS;
        }

        $this->info('KBS General Question Bank Refresh');
        $this->newLine();

        try {
            $generator = new GeneralQuestionGenerator(
                (string) config('quiz.kbs_knowledge_base_path'),
                (string) config('quiz.general_question_generator_version'),
                new GeneralQuestionValidator,
            );
            $result = $generator->generate();
        } catch (Throwable $exception) {
            $this->error('Generation failed before any database change was made:');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $byCategory = $result['questionsByCategory'];
        $bankErrors = (new GeneralQuestionValidator)->validateBank($byCategory);
        if ($bankErrors !== []) {
            $this->error('Validation failed — the existing question bank was NOT touched:');
            foreach ($bankErrors as $error) {
                $this->line("  - {$error}");
            }

            return self::FAILURE;
        }

        foreach (ReportTestCategory::cases() as $category) {
            $count = count($byCategory[$category->value]);
            $this->line(sprintf('%-18s%d', $category->value.':', $count));
        }
        $total = array_sum(array_map('count', $byCategory));
        $this->line(str_repeat('-', 32));
        $this->line(sprintf('%-18s%d', 'Total:', $total));
        $this->newLine();

        if ($result['droppedInvalidCount'] > 0 || $result['droppedDuplicateCount'] > 0) {
            $this->line("Candidates dropped by per-question validation: {$result['droppedInvalidCount']}");
            $this->line("Candidates dropped as near-duplicates: {$result['droppedDuplicateCount']}");
        }
        if ($result['skippedLiverRuleCodes'] !== []) {
            $this->line('Liver rules with no safe trigger representation (skipped): '.implode(', ', $result['skippedLiverRuleCodes']));
        }

        $quizSessionsBefore = QuizSession::query()->count();
        $snapshotsBefore = QuizQuestionSnapshot::query()->count();
        $answersBefore = StudentAnswer::query()->count();

        try {
            [$oldGeneratedRemoved, $devFixturesRemoved, $stored] = DB::transaction(function () use ($byCategory): array {
                $oldGeneratedRemoved = Question::query()->where('source', 'KBS_GENERATED')->delete();
                $devFixturesRemoved = Question::query()
                    ->where('question_text_json->en', 'like', '[DEV FIXTURE]%')
                    ->delete();

                $stored = 0;
                $now = now();
                foreach ($byCategory as $questions) {
                    foreach ($questions as $question) {
                        Question::query()->create([
                            'category' => $question->category,
                            'type' => QuizQuestionCategory::General,
                            'question_text_json' => $question->questionText,
                            'options_json' => $this->optionsForStorage($question->options),
                            'correct_option_id' => $question->correctOptionId,
                            'explanation_json' => $question->explanation,
                            'active' => true,
                            'content_version' => 1,
                            'review_status' => QuestionReviewStatus::GeneratedPendingReview,
                            'source' => 'KBS_GENERATED',
                            'source_type' => $question->sourceType,
                            'source_id' => $question->sourceId,
                            'template_family' => $question->templateFamily,
                            'generator_version' => $question->generatorVersion,
                            'stable_source_key' => $question->stableSourceKey,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $stored++;
                    }
                }

                return [$oldGeneratedRemoved, $devFixturesRemoved, $stored];
            }, 3);
        } catch (Throwable $exception) {
            $this->error('Database replacement failed and was rolled back — the previous bank is unchanged:');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Old generated questions removed: {$oldGeneratedRemoved}");
        $this->line("DEV fixture questions removed: {$devFixturesRemoved}");
        $this->line("New generated questions stored: {$stored}");
        $this->newLine();

        $quizSessionsAfter = QuizSession::query()->count();
        $snapshotsAfter = QuizQuestionSnapshot::query()->count();
        $answersAfter = StudentAnswer::query()->count();
        $this->line('Quiz sessions modified: '.abs($quizSessionsAfter - $quizSessionsBefore));
        $this->line('Question snapshots modified: '.abs($snapshotsAfter - $snapshotsBefore));
        $this->line('Student answers modified: '.abs($answersAfter - $answersBefore));

        if ($quizSessionsAfter !== $quizSessionsBefore || $snapshotsAfter !== $snapshotsBefore || $answersAfter !== $answersBefore) {
            // This command never writes to these tables - if their counts changed, a
            // concurrent process did it, not a bug in this refresh. Surfaced loudly
            // rather than silently reported as "0 modified" so it is never missed.
            throw new RuntimeException('Historical quiz tables changed during this refresh - investigate before trusting this run.');
        }

        $this->newLine();
        $this->info('Refresh completed successfully.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{en: string, ar: string}>  $options
     * @return list<array{id: string, text: array{en: string, ar: string}}>
     */
    private function optionsForStorage(array $options): array
    {
        $result = [];
        foreach ($options as $id => $text) {
            $result[] = ['id' => $id, 'text' => $text];
        }

        return $result;
    }
}
