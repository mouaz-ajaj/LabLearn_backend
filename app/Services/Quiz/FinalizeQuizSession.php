<?php

namespace App\Services\Quiz;

use App\Enums\AnalysisStatus;
use App\Enums\QuestionReviewStatus;
use App\Enums\QuizQuestionCategory;
use App\Enums\QuizSessionStatus;
use App\Models\Question;
use App\Models\QuizSession;
use Illuminate\Support\Facades\DB;

/**
 * Turns a PREPARING QuizSession into READY (or FAILED) by selecting General
 * questions and, when a Case-Specific Analysis is linked and has finished, asking
 * CaseSpecificQuestionProvider for grounded questions — then freezing everything
 * into immutable QuizQuestionSnapshot rows.
 *
 * Called from two places:
 *  - StartQuizSession, synchronously, when the linked Analysis is already terminal
 *    (SUCCEEDED/FAILED) at creation time — the common warm-cache/no-KBS-needed path.
 *  - FinalizeQuizPreparation (queued job), asynchronously, once a KBS-bound Analysis
 *    that was still QUEUED/PROCESSING at creation time finishes.
 *
 * Idempotent/safe to call more than once for the same session: a session that is no
 * longer PREPARING (or whose linked Analysis has not reached a terminal state yet) is
 * returned unchanged.
 */
class FinalizeQuizSession
{
    public function __construct(
        private readonly CaseSpecificQuestionProvider $caseSpecificProvider,
    ) {}

    public function handle(QuizSession $session): QuizSession
    {
        return DB::transaction(function () use ($session): QuizSession {
            $locked = QuizSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== QuizSessionStatus::Preparing) {
                return $locked;
            }

            $analysis = $locked->analysis_id !== null ? $locked->analysis()->first() : null;
            if ($analysis !== null && in_array($analysis->status, [AnalysisStatus::Queued, AnalysisStatus::Processing], true)) {
                // Still waiting on KBS — nothing to finalize yet.
                return $locked;
            }

            $report = $locked->report()->firstOrFail();
            $set = $locked->verifiedResultSet()->firstOrFail();

            $generalQuestions = Question::query()
                ->where('category', $locked->report_category)
                ->where('type', QuizQuestionCategory::General->value)
                ->where('active', true)
                ->when(
                    (bool) config('quiz.require_approved_general_questions'),
                    fn ($query) => $query->where('review_status', QuestionReviewStatus::Approved->value),
                )
                ->inRandomOrder()
                ->limit($locked->target_general_count)
                ->get();

            $caseSpecific = $analysis !== null && $analysis->status === AnalysisStatus::Succeeded
                ? $this->caseSpecificProvider->provide($report, $set, $analysis->load(['ruleTraces']), $locked->target_case_specific_count)
                : collect();

            $sequence = 1;
            foreach ($generalQuestions as $question) {
                $this->createSnapshot($locked, $sequence++, QuizQuestionCategory::General, [
                    'question_text' => $question->question_text_json,
                    'options' => $this->optionsToMap($question->options_json),
                    'option_order' => $this->shuffledOptionIds($question->options_json),
                    'correct_option_id' => $question->correct_option_id,
                    'explanation' => $question->explanation_json,
                    'source_question_id' => $question->getKey(),
                    'source_question_version' => $question->content_version,
                ]);
            }
            foreach ($caseSpecific as $item) {
                $this->createSnapshot($locked, $sequence++, QuizQuestionCategory::CaseSpecific, $item);
            }

            $actualGeneral = $generalQuestions->count();
            $actualCaseSpecific = $caseSpecific->count();
            $actualTotal = $actualGeneral + $actualCaseSpecific;

            $locked->update([
                'actual_general_count' => $actualGeneral,
                'actual_case_specific_count' => $actualCaseSpecific,
                'actual_total' => $actualTotal,
                'status' => $actualTotal > 0 ? QuizSessionStatus::Ready : QuizSessionStatus::Failed,
                'prepare_error_code' => $actualTotal > 0 ? null : 'QUIZ_NO_ELIGIBLE_QUESTIONS',
            ]);

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function createSnapshot(QuizSession $session, int $sequence, QuizQuestionCategory $category, array $data): void
    {
        $session->questionSnapshots()->create([
            'sequence' => $sequence,
            'question_category' => $category->value,
            'question_text_json' => $data['question_text'],
            'options_json' => $data['options'],
            'option_order_json' => $data['option_order'],
            'correct_option_id' => $data['correct_option_id'],
            'explanation_json' => $data['explanation'],
            'source_question_id' => $data['source_question_id'] ?? null,
            'source_question_version' => $data['source_question_version'] ?? null,
            'case_specific_template_id' => $data['case_specific_template_id'] ?? null,
            'case_specific_template_version' => $data['case_specific_template_version'] ?? null,
            'evidence_json' => $data['evidence'] ?? null,
            'rule_code' => $data['rule_code'] ?? null,
            'analyte_refs_json' => $data['analyte_refs'] ?? null,
        ]);
    }

    /**
     * @param  array<int, array{id: string, text: array<string, string>}>  $options
     * @return array<string, array<string, string>>
     */
    private function optionsToMap(array $options): array
    {
        $map = [];
        foreach ($options as $option) {
            $map[$option['id']] = $option['text'];
        }

        return $map;
    }

    /**
     * @param  array<int, array{id: string, text: array<string, string>}>  $options
     * @return list<string>
     */
    private function shuffledOptionIds(array $options): array
    {
        $ids = array_map(static fn (array $option): string => $option['id'], $options);
        shuffle($ids);

        return $ids;
    }
}
