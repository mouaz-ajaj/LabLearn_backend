<?php

use App\Enums\QuestionReviewStatus;
use App\Enums\ReportTestCategory;
use App\Models\Question;
use App\Models\QuizQuestionSnapshot;
use App\Models\QuizSession;
use App\Models\StudentAnswer;
use App\Services\Quiz\CaseSpecificQuestionProvider;

// Reuses phase3bFixture()/phase3bToken()/phase3bSeedQuestions()/phase3bReadyQuiz() etc.
// defined as global functions in tests/Feature/Phase3B/QuizApiTest.php.

test('the command is a safe no-op without --force when the start-on-boot config is disabled', function () {
    config(['quiz.refresh_general_bank_on_start' => false]);
    Question::query()->where('source', 'KBS_GENERATED')->delete();

    $this->artisan('quiz:refresh-general-bank')->assertExitCode(0);

    expect(Question::query()->where('source', 'KBS_GENERATED')->count())->toBe(0);
});

test('--force runs the refresh even when the start-on-boot config is disabled', function () {
    config(['quiz.refresh_general_bank_on_start' => false]);

    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);

    expect(Question::query()->where('source', 'KBS_GENERATED')->count())->toBeGreaterThan(300);
});

test('the refresh removes known DEV FIXTURE questions, replaces old generated questions, and preserves unrelated manually authored questions', function () {
    $manual = Question::factory()->forCategory(ReportTestCategory::Cbc)->create(['source' => null]);
    Question::query()->create([
        'category' => ReportTestCategory::Cbc, 'type' => 'GENERAL',
        'question_text_json' => ['en' => '[DEV FIXTURE] old placeholder', 'ar' => '[بيانات تجريبية] قديمة'],
        'options_json' => [
            ['id' => 'a', 'text' => ['en' => 'A', 'ar' => 'أ']], ['id' => 'b', 'text' => ['en' => 'B', 'ar' => 'ب']],
            ['id' => 'c', 'text' => ['en' => 'C', 'ar' => 'ج']], ['id' => 'd', 'text' => ['en' => 'D', 'ar' => 'د']],
        ],
        'correct_option_id' => 'a', 'explanation_json' => ['en' => 'x', 'ar' => 'x'],
        'active' => true, 'content_version' => 1, 'review_status' => QuestionReviewStatus::Draft,
    ]);
    $staleGenerated = Question::query()->create([
        'category' => ReportTestCategory::Cbc, 'type' => 'GENERAL',
        'question_text_json' => ['en' => 'stale generated question', 'ar' => 'سؤال قديم'],
        'options_json' => [
            ['id' => 'a', 'text' => ['en' => 'A', 'ar' => 'أ']], ['id' => 'b', 'text' => ['en' => 'B', 'ar' => 'ب']],
            ['id' => 'c', 'text' => ['en' => 'C', 'ar' => 'ج']], ['id' => 'd', 'text' => ['en' => 'D', 'ar' => 'د']],
        ],
        'correct_option_id' => 'a', 'explanation_json' => ['en' => 'x', 'ar' => 'x'],
        'active' => true, 'content_version' => 1, 'review_status' => QuestionReviewStatus::GeneratedPendingReview,
        'source' => 'KBS_GENERATED', 'stable_source_key' => 'STALE:TEST:KEY:v0',
    ]);

    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);

    expect(Question::query()->whereKey($manual->getKey())->exists())->toBeTrue()
        ->and(Question::query()->where('question_text_json->en', 'like', '[DEV FIXTURE]%')->count())->toBe(0)
        ->and(Question::query()->whereKey($staleGenerated->getKey())->exists())->toBeFalse()
        ->and(Question::query()->where('source', 'KBS_GENERATED')->where('stable_source_key', 'STALE:TEST:KEY:v0')->exists())->toBeFalse()
        ->and(Question::query()->where('source', 'KBS_GENERATED')->count())->toBeGreaterThan(300);
});

test('generated questions are stored as GENERATED_PENDING_REVIEW with full traceability metadata', function () {
    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);

    $sample = Question::query()->where('source', 'KBS_GENERATED')->where('category', 'CBC')->first();
    expect($sample->review_status)->toBe(QuestionReviewStatus::GeneratedPendingReview)
        ->and($sample->source_type)->not->toBeNull()
        ->and($sample->source_id)->not->toBeNull()
        ->and($sample->template_family)->not->toBeNull()
        ->and($sample->generator_version)->not->toBeNull()
        ->and($sample->stable_source_key)->not->toBeNull();
});

test('running the refresh never modifies historical quiz sessions, snapshots, or student answers', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 5);
    app()->bind(
        CaseSpecificQuestionProvider::class,
        fn () => new FakeQuizCaseSpecificQuestionProvider(0),
    );
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->orderBy('sequence')->first();
    $this->withToken(phase3bToken($user))->postJson('/api/v1/quiz/'.$result['quizId'].'/answers', [
        'question_snapshot_id' => $snapshot->getKey(),
        'selected_option_id' => $snapshot->correct_option_id,
    ])->assertOk();

    $beforeSession = QuizSession::query()->findOrFail($result['quizId'])->toArray();
    $beforeSnapshots = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->orderBy('id')->get()->toArray();
    $beforeAnswers = StudentAnswer::query()->where('quiz_question_snapshot_id', $snapshot->getKey())->get()->toArray();

    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);

    expect(QuizSession::query()->findOrFail($result['quizId'])->toArray())->toBe($beforeSession)
        ->and(QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->orderBy('id')->get()->toArray())->toBe($beforeSnapshots)
        ->and(StudentAnswer::query()->where('quiz_question_snapshot_id', $snapshot->getKey())->get()->toArray())->toBe($beforeAnswers);
});

test('a source question deleted from the bank by refresh does not change an already-built snapshot that referenced it', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 5);
    app()->bind(
        CaseSpecificQuestionProvider::class,
        fn () => new FakeQuizCaseSpecificQuestionProvider(0),
    );
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->first();
    $originalText = $snapshot->question_text_json;
    $sourceQuestionId = $snapshot->source_question_id;

    // The refresh only ever deletes source=KBS_GENERATED / [DEV FIXTURE] rows, so the
    // factory-seeded source question this snapshot points to survives — but even if a
    // future change made it disappear, the snapshot itself must still read correctly.
    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);

    $snapshot->refresh();
    expect($snapshot->question_text_json)->toBe($originalText)
        ->and(Question::query()->whereKey($sourceQuestionId)->exists())->toBeTrue();
});
