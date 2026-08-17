<?php

use App\Enums\PatientSex;
use App\Enums\ReportSourceType;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Models\Question;
use App\Models\QuizQuestionSnapshot;
use App\Models\QuizSession;
use App\Models\Report;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Quiz\StartQuizSession;
use App\Services\Quiz\SubmitQuizAnswer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 4D fixtures - deliberately self-contained (not reused from Phase 3B's
| own phase3bXxx() helpers), matching the isolation convention already
| established by Phase 4C's phase4cXxx() helpers: each phase's test file stays
| independently understandable without hidden cross-file coupling.
|--------------------------------------------------------------------------
*/

function phase4dFixture(?User $user = null, ReportTestCategory $category = ReportTestCategory::Cbc): array
{
    $user ??= User::factory()->student('4')->create();
    $report = Report::factory()->for($user)->create([
        'test_category' => $category,
        'source_type' => ReportSourceType::Image,
        'status' => ReportStatus::Verified,
        'patient_age_years' => 24,
        'patient_sex' => PatientSex::Female,
    ]);
    $set = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 1, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 24, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'phase4d-fixture-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => 'MATCH', 'category_gate_category' => $category->value,
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);
    $set->results()->create(['label' => 'X', 'value' => '10', 'unit' => 'unit', 'reference_range' => '1-20', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1]);

    return [$user, $report, $set->fresh('results')];
}

function phase4dToken(User $user): string
{
    return $user->createToken('phase4d-test')->plainTextToken;
}

function phase4dSeedQuestions(ReportTestCategory $category, int $count): void
{
    Question::factory()->count($count)->forCategory($category)->state(['active' => true])->create();
}

function phase4dKbsMetadata(): array
{
    return [
        'input_schema_version' => '1', 'output_schema_version' => '1', 'engine_version' => '1.0.2',
        'ruleset_version' => '2026.07.24.2', 'analyte_catalog_version' => '2026.07.24.2',
        'knowledge_base_version' => '2026.07.24.2', 'supported_categories' => ['CBC', 'DIABETES', 'LIVER_FUNCTION'],
    ];
}

function phase4dKbsValidateClean(): array
{
    return ['success' => true, 'blocking' => false, 'ready_for_analysis' => true, 'issues' => []];
}

/** A structurally valid, category-agnostic /v1/analyze response with no conclusions/rule traces (General-only quiz path) - real shape, deliberately inert content since these tests only need dynamic, controllable scoring, not real Case-Specific matching. */
function phase4dKbsAnalyzePayload(string $category, int $setId, int $version): array
{
    $analyteId = match ($category) {
        'CBC' => 'hemoglobin',
        'DIABETES' => 'hba1c',
        'LIVER_FUNCTION' => 'alt',
        default => throw new InvalidArgumentException("No fixture for {$category}"),
    };

    return [
        'success' => true, 'schema_version' => '1', 'input_schema_version' => '1', 'output_schema_version' => '1',
        'engine_version' => '1.0.2', 'ruleset_version' => '2026.07.24.2', 'analyte_catalog_version' => '2026.07.24.2',
        'knowledge_base_version' => '2026.07.24.2', 'request_id' => 'phase4d-test', 'category' => $category,
        'verified_result_set' => ['id' => $setId, 'version' => $version], 'status' => 'no_patterns_detected',
        'category_validation' => ['status' => 'MATCH', 'matched_analytes' => [$analyteId], 'other_category_analytes' => [], 'unsupported_analytes' => [], 'missing_required_evidence' => [], 'reason' => 'SUPPORTED_CATEGORY_EVIDENCE'],
        'normalized_results' => [['source_id' => 1, 'analyte_id' => $analyteId, 'display_name' => $analyteId, 'value' => 10.0, 'unit' => 'unit', 'original_value' => 10.0, 'original_unit' => 'unit', 'reference_range' => ['low' => 1.0, 'high' => 20.0], 'status' => 'normal']],
        'facts' => [['analyte_id' => $analyteId, 'status' => 'normal']],
        'conclusions' => [], 'rule_traces' => [], 'missing_information' => [], 'warnings' => [],
        'summary' => ['en' => 'No patterns detected.', 'ar' => null],
        'disclaimer' => ['en' => 'Educational decision support only.', 'ar' => null],
    ];
}

/** Builds a READY quiz session entirely through direct service calls (no HTTP, no token) - keeps fixture-building independent of Sanctum's per-test-method guard-caching, matching the phase3bReadyQuizViaService precedent. */
function phase4dReadyQuiz(User $user, Report $report, VerifiedResultSet $set, string $category): QuizSession
{
    Http::fake([
        '*/v1/metadata' => Http::response(phase4dKbsMetadata()),
        '*/v1/validate' => Http::response(phase4dKbsValidateClean()),
        '*/v1/analyze' => Http::response(phase4dKbsAnalyzePayload($category, $set->getKey(), $set->version)),
    ]);

    return app(StartQuizSession::class)->handle($report, $set, $user)->fresh();
}

/** Answers every question in sequence order, the first $numCorrect correctly and the rest incorrectly, completing the session via the real scoring service - never assigns a raw score/status directly. */
function phase4dCompleteQuiz(QuizSession $quiz, int $numCorrect, ?Carbon $completedAt = null): QuizSession
{
    $snapshots = QuizQuestionSnapshot::query()->where('quiz_session_id', $quiz->getKey())->orderBy('sequence')->get();
    $service = app(SubmitQuizAnswer::class);
    foreach ($snapshots as $index => $snapshot) {
        $correct = $index < $numCorrect;
        $optionId = $correct
            ? $snapshot->correct_option_id
            : collect($snapshot->option_order_json)->first(fn ($id) => $id !== $snapshot->correct_option_id);
        $service->handle($quiz, $snapshot->getKey(), $optionId);
    }

    $quiz = $quiz->fresh();
    if ($completedAt !== null) {
        $quiz->update(['completed_at' => $completedAt]);
        $quiz = $quiz->fresh();
    }

    return $quiz;
}

test('unauthenticated request is rejected', function () {
    $this->getJson('/api/v1/students/me/quiz-history')->assertUnauthorized();
});

test('regular user is forbidden from listing quiz history', function () {
    $user = User::factory()->regular()->create();

    $this->withToken(phase4dToken($user))
        ->getJson('/api/v1/students/me/quiz-history')
        ->assertForbidden()
        ->assertJsonPath('error_code', 'QUIZ_STUDENT_ONLY');
});

test('a student with zero completed quizzes gets an honest empty summary, not a fabricated percentage', function () {
    $user = User::factory()->student('4')->create();

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history')->assertOk();

    $response->assertJsonPath('data.summary.completed_quizzes', 0)
        ->assertJsonPath('data.summary.correct_answers', 0)
        ->assertJsonPath('data.summary.total_questions', 0)
        ->assertJsonPath('data.summary.overall_percentage', null)
        ->assertJsonCount(0, 'data.items');
});

test('an in progress preparing ready or failed session is excluded from history and statistics', function () {
    phase4dSeedQuestions(ReportTestCategory::Cbc, 5);
    [$user, $report, $set] = phase4dFixture();
    $ready = phase4dReadyQuiz($user, $report, $set, 'CBC');
    expect($ready->status->value)->toBe('READY');

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history')->assertOk();

    $response->assertJsonPath('data.summary.completed_quizzes', 0)
        ->assertJsonCount(0, 'data.items');
});

test('the weighted overall percentage matches sum of correct over sum of total, not an average of percentages', function () {
    // Quiz A: 12/15, Quiz B: 16/20, Quiz C: 10/16 -> overall 38/51 = 74.5% (not avg(80,80,62.5)=74.166...)
    // Raised above the default 14-question target so each quiz's actual_total matches its full seeded bank size.
    config(['quiz.preferred_general_count' => 20]);
    $user = User::factory()->student('4')->create();
    phase4dSeedQuestions(ReportTestCategory::Cbc, 15);
    [, $reportA, $setA] = phase4dFixture($user, ReportTestCategory::Cbc);
    phase4dCompleteQuiz(phase4dReadyQuiz($user, $reportA, $setA, 'CBC'), 12);

    phase4dSeedQuestions(ReportTestCategory::Diabetes, 20);
    [, $reportB, $setB] = phase4dFixture($user, ReportTestCategory::Diabetes);
    phase4dCompleteQuiz(phase4dReadyQuiz($user, $reportB, $setB, 'DIABETES'), 16);

    phase4dSeedQuestions(ReportTestCategory::LiverFunction, 16);
    [, $reportC, $setC] = phase4dFixture($user, ReportTestCategory::LiverFunction);
    phase4dCompleteQuiz(phase4dReadyQuiz($user, $reportC, $setC, 'LIVER_FUNCTION'), 10);

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history')->assertOk();

    $response->assertJsonPath('data.summary.completed_quizzes', 3)
        ->assertJsonPath('data.summary.correct_answers', 38)
        ->assertJsonPath('data.summary.total_questions', 51)
        ->assertJsonPath('data.summary.overall_percentage', 74.5);
});

test('newest completed quiz is listed first with a stable secondary order', function () {
    $user = User::factory()->student('4')->create();
    phase4dSeedQuestions(ReportTestCategory::Cbc, 5);

    [, $reportOld, $setOld] = phase4dFixture($user, ReportTestCategory::Cbc);
    $old = phase4dCompleteQuiz(phase4dReadyQuiz($user, $reportOld, $setOld, 'CBC'), 5, now()->subDays(10));

    [, $reportNew, $setNew] = phase4dFixture($user, ReportTestCategory::Cbc);
    $new = phase4dCompleteQuiz(phase4dReadyQuiz($user, $reportNew, $setNew, 'CBC'), 5, now()->subDay());

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history')->assertOk();

    expect($response->json('data.items.0.id'))->toBe($new->getKey())
        ->and($response->json('data.items.1.id'))->toBe($old->getKey());
});

test('pagination returns the requested page size and reports has more correctly', function () {
    $user = User::factory()->student('4')->create();
    phase4dSeedQuestions(ReportTestCategory::Cbc, 3);
    for ($i = 0; $i < 3; $i++) {
        [, $report, $set] = phase4dFixture($user, ReportTestCategory::Cbc);
        phase4dCompleteQuiz(phase4dReadyQuiz($user, $report, $set, 'CBC'), 3, now()->subDays($i));
    }

    $first = $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history?per_page=2&page=1')->assertOk();
    $first->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonPath('data.pagination.has_more', true);

    $second = $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history?per_page=2&page=2')->assertOk();
    $second->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.pagination.has_more', false);
});

test('category filter narrows the item list but the summary stays the students true overall performance', function () {
    $user = User::factory()->student('4')->create();
    phase4dSeedQuestions(ReportTestCategory::Cbc, 10);
    phase4dSeedQuestions(ReportTestCategory::Diabetes, 10);
    [, $reportCbc, $setCbc] = phase4dFixture($user, ReportTestCategory::Cbc);
    phase4dCompleteQuiz(phase4dReadyQuiz($user, $reportCbc, $setCbc, 'CBC'), 10);
    [, $reportDiabetes, $setDiabetes] = phase4dFixture($user, ReportTestCategory::Diabetes);
    phase4dCompleteQuiz(phase4dReadyQuiz($user, $reportDiabetes, $setDiabetes, 'DIABETES'), 5);

    $response = $this->withToken(phase4dToken($user))
        ->getJson('/api/v1/students/me/quiz-history?test_category=DIABETES')
        ->assertOk();

    $response->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.test_category', 'DIABETES')
        ->assertJsonPath('data.summary.completed_quizzes', 2)
        ->assertJsonPath('data.summary.total_questions', 20);
});

test('a student only ever sees their own quiz history, never another students', function () {
    $owner = User::factory()->student('4')->create();
    $other = User::factory()->student('2')->create();
    phase4dSeedQuestions(ReportTestCategory::Cbc, 5);
    [, $report, $set] = phase4dFixture($owner, ReportTestCategory::Cbc);
    phase4dCompleteQuiz(phase4dReadyQuiz($owner, $report, $set, 'CBC'), 5);

    $response = $this->withToken(phase4dToken($other))->getJson('/api/v1/students/me/quiz-history')->assertOk();

    $response->assertJsonPath('data.summary.completed_quizzes', 0)
        ->assertJsonCount(0, 'data.items');
});

test('each history item exposes score total percentage and composition using that sessions own actual total', function () {
    config(['quiz.preferred_general_count' => 16]);
    $user = User::factory()->student('4')->create();
    phase4dSeedQuestions(ReportTestCategory::Cbc, 16);
    [, $report, $set] = phase4dFixture($user, ReportTestCategory::Cbc);
    $quiz = phase4dCompleteQuiz(phase4dReadyQuiz($user, $report, $set, 'CBC'), 13);

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history')->assertOk();

    $response->assertJsonPath('data.items.0.id', $quiz->getKey())
        ->assertJsonPath('data.items.0.report_id', $quiz->report_id)
        ->assertJsonPath('data.items.0.status', 'COMPLETED')
        ->assertJsonPath('data.items.0.score', 13)
        ->assertJsonPath('data.items.0.total', 16)
        ->assertJsonPath('data.items.0.percentage', 81.3)
        ->assertJsonPath('data.items.0.general_count', 16)
        ->assertJsonPath('data.items.0.case_specific_count', 0)
        ->assertJsonPath('data.items.0.completed_at', fn ($value) => $value !== null);
});

test('the history list endpoint never returns question snapshots or answers', function () {
    $user = User::factory()->student('4')->create();
    phase4dSeedQuestions(ReportTestCategory::Cbc, 5);
    [, $report, $set] = phase4dFixture($user, ReportTestCategory::Cbc);
    phase4dCompleteQuiz(phase4dReadyQuiz($user, $report, $set, 'CBC'), 5);

    $response = $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history')->assertOk();

    $item = $response->json('data.items.0');
    expect($item)->not->toHaveKey('questions')
        ->and($item)->not->toHaveKey('user_id')
        ->and($item)->not->toHaveKey('identity_key');
});

test('the history endpoint performs no database mutation and reruns nothing', function () {
    $user = User::factory()->student('4')->create();
    phase4dSeedQuestions(ReportTestCategory::Cbc, 5);
    [, $report, $set] = phase4dFixture($user, ReportTestCategory::Cbc);
    phase4dCompleteQuiz(phase4dReadyQuiz($user, $report, $set, 'CBC'), 5);

    $sessionsBefore = QuizSession::query()->count();
    $snapshotsBefore = QuizQuestionSnapshot::query()->count();

    Http::fake(); // any stray OCR/KBS call would fail loudly against an empty fake
    $this->withToken(phase4dToken($user))->getJson('/api/v1/students/me/quiz-history')->assertOk();

    expect(QuizSession::query()->count())->toBe($sessionsBefore)
        ->and(QuizQuestionSnapshot::query()->count())->toBe($snapshotsBefore);
});
