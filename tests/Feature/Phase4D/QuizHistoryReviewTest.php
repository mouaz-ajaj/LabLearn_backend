<?php

use App\Enums\QuizQuestionCategory;
use App\Enums\ReportTestCategory;
use App\Models\Question;
use App\Models\QuizQuestionSnapshot;
use App\Models\QuizSession;
use App\Models\Report;
use App\Models\StudentAnswer;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Quiz\StartQuizSession;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 4D historical review reuses GET /api/v1/quiz/{quiz} and
| QuizSessionResource completely unchanged (see backend/docs/phase-4d-quiz-history.md
| "Detail API" for the audit conclusion) - no new detail endpoint was created.
| These tests exist to prove that reuse is safe for the specific Phase 4D
| requirements: full GENERAL + CASE_SPECIFIC review after completion, no
| correct-answer leak before completion, and snapshot immutability.
|--------------------------------------------------------------------------
*/

test('a completed quiz review exposes both general and case specific questions with student answer, correct answer, correctness, and explanation', function () {
    phase4dSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase4dFixture();
    $quiz = phase4dReadyQuizWithRealCaseSpecific($user, $report, $set);
    $quiz = phase4dCompleteQuiz($quiz, (int) floor($quiz->actual_total / 2));

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();

    $response->assertJsonPath('data.status', 'COMPLETED')
        ->assertJsonPath('data.completed_at', fn ($value) => $value !== null)
        ->assertJsonPath('data.score', $quiz->fresh()->score);

    $questions = $response->json('data.questions');
    $categories = collect($questions)->pluck('category')->unique()->values()->all();
    expect($categories)->toContain('GENERAL')
        ->and($categories)->toContain('CASE_SPECIFIC');

    foreach ($questions as $question) {
        expect($question['answered'])->not->toBeNull()
            ->and($question['answered'])->toHaveKeys(['selected_option_id', 'correct', 'correct_option_id', 'explanation', 'answered_at'])
            ->and($question['answered']['correct'])->toBeIn([true, false]);
    }
});

test('an unfinished quiz session still never exposes a correct answer or explanation for an unanswered question - regression for phase 4d history reuse', function () {
    phase4dSeedQuestions(ReportTestCategory::Cbc, 5);
    [$user, $report, $set] = phase4dFixture();
    $quiz = phase4dReadyQuiz($user, $report, $set, 'CBC');

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();

    $response->assertJsonPath('data.status', 'READY');
    foreach ($response->json('data.questions') as $question) {
        expect($question)->not->toHaveKey('correct_option_id')
            ->and($question)->not->toHaveKey('explanation')
            ->and($question['answered'])->toBeNull();
    }
});

test('a student cannot review another students completed quiz', function () {
    phase4dSeedQuestions(ReportTestCategory::Cbc, 3);
    $owner = User::factory()->student('4')->create();
    $other = User::factory()->student('2')->create();
    [, $report, $set] = phase4dFixture($owner);
    $quiz = phase4dCompleteQuiz(phase4dReadyQuiz($owner, $report, $set, 'CBC'), 3);

    $this->withToken(phase4dToken($other))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertForbidden();
});

test('editing or deactivating the source question after completion does not alter the historical review', function () {
    phase4dSeedQuestions(ReportTestCategory::Cbc, 3);
    [$user, $report, $set] = phase4dFixture();
    $quiz = phase4dCompleteQuiz(phase4dReadyQuiz($user, $report, $set, 'CBC'), 2);

    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $quiz->getKey())->where('question_category', QuizQuestionCategory::General->value)->first();
    $originalQuestionText = $snapshot->question_text_json;
    $originalOptions = $snapshot->options_json;
    $originalCorrect = $snapshot->correct_option_id;
    $originalExplanation = $snapshot->explanation_json;

    Question::query()->whereKey($snapshot->source_question_id)->update([
        'question_text_json' => ['en' => '[REFRESHED] Completely different question', 'ar' => 'سؤال مختلف'],
        'options_json' => ['z' => ['en' => 'New option', 'ar' => 'خيار جديد']],
        'correct_option_id' => 'z',
        'explanation_json' => ['en' => 'A brand new explanation.', 'ar' => 'شرح جديد'],
        'active' => false,
        'content_version' => 99,
    ]);

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();
    $reviewed = collect($response->json('data.questions'))->firstWhere('id', $snapshot->getKey());

    expect($reviewed['question'])->toBe($originalQuestionText)
        ->and(collect($reviewed['options'])->pluck('id')->sort()->values()->all())->toBe(collect($originalOptions)->keys()->sort()->values()->all())
        ->and($reviewed['answered']['correct_option_id'])->toBe($originalCorrect)
        ->and($reviewed['answered']['explanation'])->toBe($originalExplanation);
});

test('refreshing the general question bank for this category does not alter an already completed historical quiz', function () {
    phase4dSeedQuestions(ReportTestCategory::Cbc, 3);
    [$user, $report, $set] = phase4dFixture();
    $quiz = phase4dCompleteQuiz(phase4dReadyQuiz($user, $report, $set, 'CBC'), 3);
    $snapshotIdsBefore = QuizQuestionSnapshot::query()->where('quiz_session_id', $quiz->getKey())->pluck('id')->sort()->values()->all();

    // Simulate a bank refresh: deactivate every existing question and seed a brand new batch.
    Question::query()->update(['active' => false]);
    phase4dSeedQuestions(ReportTestCategory::Cbc, 20);

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();
    $snapshotIdsAfter = collect($response->json('data.questions'))->pluck('id')->sort()->values()->all();

    expect($snapshotIdsAfter)->toBe($snapshotIdsBefore)
        ->and($response->json('data.actual.total'))->toBe(3);
});

test('reviewing a completed quiz performs no database mutation', function () {
    phase4dSeedQuestions(ReportTestCategory::Cbc, 3);
    [$user, $report, $set] = phase4dFixture();
    $quiz = phase4dCompleteQuiz(phase4dReadyQuiz($user, $report, $set, 'CBC'), 3);
    $answersBefore = StudentAnswer::query()->count();
    $snapshotsBefore = QuizQuestionSnapshot::query()->count();

    Http::fake();
    $this->withToken(phase4dToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();

    expect(StudentAnswer::query()->count())->toBe($answersBefore)
        ->and(QuizQuestionSnapshot::query()->count())->toBe($snapshotsBefore);
});

/** Real CBC /v1/analyze payload with R001+R002 firing, so the resulting quiz actually contains Case-Specific questions (not simulated). */
function phase4dReadyQuizWithRealCaseSpecific(User $user, Report $report, VerifiedResultSet $set): QuizSession
{
    $conclusions = [
        ['code' => 'possible_anemia_pattern', 'level' => 'pattern', 'title' => ['en' => 'Possible anemia pattern', 'ar' => null], 'summary' => ['en' => 'Low hemoglobin may suggest an anemia pattern.', 'ar' => null], 'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']], 'rule_codes' => ['R001']],
        ['code' => 'microcytic_anemia_pattern', 'level' => 'pattern', 'title' => ['en' => 'Possible microcytic anemia pattern', 'ar' => null], 'summary' => ['en' => 'Anemia with low MCV suggests small red blood cells.', 'ar' => null], 'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low'], ['source_id' => 2, 'analyte_id' => 'mcv', 'label' => 'Mean Corpuscular Volume', 'value' => 72.0, 'unit' => 'fL', 'status' => 'low']], 'rule_codes' => ['R002']],
    ];
    $ruleTraces = [
        ['rule_code' => 'R001', 'rule_version' => 1, 'fired' => true, 'conditions' => [], 'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']], 'conclusion_codes' => ['possible_anemia_pattern']],
        ['rule_code' => 'R002', 'rule_version' => 1, 'fired' => true, 'conditions' => [], 'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low'], ['source_id' => 2, 'analyte_id' => 'mcv', 'label' => 'Mean Corpuscular Volume', 'value' => 72.0, 'unit' => 'fL', 'status' => 'low']], 'conclusion_codes' => ['microcytic_anemia_pattern']],
    ];
    $payload = phase4dKbsAnalyzePayload('CBC', $set->getKey(), $set->version);
    $payload['status'] = 'patterns_detected';
    $payload['normalized_results'] = [
        ['source_id' => 1, 'analyte_id' => 'hemoglobin', 'display_name' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'original_value' => 9.5, 'original_unit' => 'g/dL', 'reference_range' => ['low' => 12.0, 'high' => 16.0], 'status' => 'low'],
        ['source_id' => 2, 'analyte_id' => 'mcv', 'display_name' => 'Mean Corpuscular Volume', 'value' => 72.0, 'unit' => 'fL', 'original_value' => 72.0, 'original_unit' => 'fL', 'reference_range' => ['low' => 80.0, 'high' => 100.0], 'status' => 'low'],
    ];
    $payload['facts'] = [['analyte_id' => 'hemoglobin', 'status' => 'low'], ['analyte_id' => 'mcv', 'status' => 'low']];
    $payload['conclusions'] = $conclusions;
    $payload['rule_traces'] = $ruleTraces;

    Http::fake([
        '*/v1/metadata' => Http::response(phase4dKbsMetadata()),
        '*/v1/validate' => Http::response(phase4dKbsValidateClean()),
        '*/v1/analyze' => Http::response($payload),
    ]);

    return app(StartQuizSession::class)->handle($report, $set, $user)->fresh();
}
