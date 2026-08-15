<?php

use App\Enums\ReportTestCategory;
use App\Models\Question;
use App\Models\QuizQuestionSnapshot;
use App\Services\Quiz\CaseSpecificQuestionProvider;

// Reuses phase3bFixture()/phase3bToken()/phase3bReadyQuiz()/FakeQuizCaseSpecificQuestionProvider
// defined as global functions/classes in tests/Feature/Phase3B/QuizApiTest.php.

test('CBC quiz general questions come from the real generated bank, capped at 14, with no placeholder text', function () {
    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.actual.general', 14);
    $snapshots = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->get();
    foreach ($snapshots as $snapshot) {
        $en = $snapshot->question_text_json['en'];
        expect($en)->not->toContain('[DEV FIXTURE]', 'Test fixture question', 'Option A');
        $sourceQuestion = Question::query()->find($snapshot->source_question_id);
        expect($sourceQuestion->category)->toBe(ReportTestCategory::Cbc)
            ->and($sourceQuestion->source)->toBe('KBS_GENERATED');
    }
});

test('DIABETES quiz selects up to 14 category-only generated general questions', function () {
    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));

    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Diabetes);
    $result = phase3bReadyQuiz($user, $report, $set, 'DIABETES');

    $result['response']->assertJsonPath('data.actual.general', 14);
    $snapshots = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->get();
    foreach ($snapshots as $snapshot) {
        $sourceQuestion = Question::query()->find($snapshot->source_question_id);
        expect($sourceQuestion->category)->toBe(ReportTestCategory::Diabetes);
    }
});

test('LIVER_FUNCTION quiz selects up to 14 category-only generated general questions', function () {
    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));

    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::LiverFunction);
    $result = phase3bReadyQuiz($user, $report, $set, 'LIVER_FUNCTION');

    $result['response']->assertJsonPath('data.actual.general', 14);
    $snapshots = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->get();
    foreach ($snapshots as $snapshot) {
        $sourceQuestion = Question::query()->find($snapshot->source_question_id);
        expect($sourceQuestion->category)->toBe(ReportTestCategory::LiverFunction);
    }
});

test('Case-Specific generation still works unmodified alongside the new General bank (Phase 3B.3 regression)', function () {
    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.status', 'READY')
        ->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 2)
        ->assertJsonPath('data.actual.total', 16);
    $caseSpecific = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->where('question_category', 'CASE_SPECIFIC')->get();
    expect($caseSpecific->pluck('case_specific_template_id')->sort()->values()->all())->toBe(['cbc-cs-r001', 'cbc-cs-r002']);
});

test('a category with fewer than 14 active generated questions still produces a valid smaller quiz', function () {
    $this->artisan('quiz:refresh-general-bank --force')->assertExitCode(0);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    // Deactivate all but 5 CBC generated questions to simulate a thin bank.
    $keep = Question::query()->where('category', 'CBC')->where('source', 'KBS_GENERATED')->limit(5)->pluck('id');
    Question::query()->where('category', 'CBC')->where('source', 'KBS_GENERATED')->whereNotIn('id', $keep)->update(['active' => false]);

    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.actual.general', 5);
});
