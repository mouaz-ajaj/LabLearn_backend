<?php

use App\Enums\QuizSessionStatus;
use App\Enums\ReportTestCategory;
use App\Jobs\FinalizeQuizPreparation;
use App\Jobs\ProcessReportAnalysis;
use App\Models\Analysis;
use App\Models\QuizQuestionSnapshot;
use App\Models\QuizSession;
use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsRequestMapper;
use App\Services\Quiz\FinalizeQuizSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Reuses phase3bFixture()/phase3bToken()/phase3bSeedQuestions()/phase3bKbsMetadata()/
// phase3bKbsValidateClean()/phase3bKbsAnalyzePayload()/phase3bReadyQuiz() defined as
// global functions in QuizApiTest.php — Pest loads every Feature test file into one
// process, so they are already available here without a require/import.

test('cbc: kbs analysis succeeds, structured evidence is persisted, general questions are selected, and a real case specific template matches', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $analysis = Analysis::query()->findOrFail($result['analysisId']);
    expect($analysis->status->value)->toBe('SUCCEEDED')
        ->and($analysis->conclusions()->count())->toBe(2)
        ->and($analysis->ruleTraces()->where('fired', true)->count())->toBe(2);

    $result['response']->assertJsonPath('data.status', 'READY')
        ->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 2);

    $caseSpecific = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->where('question_category', 'CASE_SPECIFIC')->get();
    expect($caseSpecific->pluck('case_specific_template_id')->sort()->values()->all())->toBe(['cbc-cs-r001', 'cbc-cs-r002']);
    foreach ($caseSpecific as $snapshot) {
        expect($snapshot->evidence_json)->not->toBeEmpty()
            ->and($snapshot->rule_code)->not->toBeNull()
            ->and($snapshot->analyte_refs_json)->not->toBeEmpty();
    }
});

test('diabetes: a single independently usable analyte in the diabetes range triggers a matching case specific template', function () {
    phase3bSeedQuestions(ReportTestCategory::Diabetes, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Diabetes);

    $result = phase3bReadyQuiz($user, $report, $set, 'DIABETES');

    $result['response']->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 1);

    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->where('question_category', 'CASE_SPECIFIC')->first();
    expect($snapshot->rule_code)->toBe('R020')
        ->and($snapshot->case_specific_template_id)->toBe('diabetes-cs-r020');
});

test('diabetes: a composite low glucose result triggers the hypoglycemia template instead', function () {
    phase3bSeedQuestions(ReportTestCategory::Diabetes, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Diabetes);

    $payload = phase3bKbsAnalyzePayload('DIABETES', $set->getKey(), $set->version);
    $payload['normalized_results'] = [
        ['source_id' => 12, 'analyte_id' => 'fasting_glucose', 'display_name' => 'Fasting Glucose', 'value' => 55.0, 'unit' => 'mg/dL', 'original_value' => 55.0, 'original_unit' => 'mg/dL', 'reference_range' => ['low' => 70.0, 'high' => 99.0], 'status' => 'low'],
    ];
    $payload['facts'] = [['analyte_id' => 'fasting_glucose', 'status' => 'low']];
    $payload['conclusions'] = [[
        'code' => 'possible_hypoglycemia_pattern', 'level' => 'pattern',
        'title' => ['en' => 'Possible hypoglycemia pattern', 'ar' => null],
        'summary' => ['en' => 'Low blood glucose may suggest hypoglycemia.', 'ar' => null],
        'evidence' => [['source_id' => 12, 'analyte_id' => 'fasting_glucose', 'label' => 'Fasting Glucose', 'value' => 55.0, 'unit' => 'mg/dL', 'status' => 'low']],
        'rule_codes' => ['R017'],
    ]];
    $payload['rule_traces'] = [[
        'rule_code' => 'R017', 'rule_version' => 1, 'fired' => true, 'conditions' => [],
        'evidence' => [['source_id' => 12, 'analyte_id' => 'fasting_glucose', 'label' => 'Fasting Glucose', 'value' => 55.0, 'unit' => 'mg/dL', 'status' => 'low']],
        'conclusion_codes' => ['possible_hypoglycemia_pattern'],
    ]];

    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);
    $created = test()->withToken(phase3bToken($user))->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])->assertCreated();
    $quiz = QuizSession::query()->findOrFail($created->json('data.id'));
    Http::fake(['*/v1/analyze' => Http::response($payload)]);
    (new ProcessReportAnalysis($quiz->analysis_id))->handle(app(KbsClient::class), app(KbsRequestMapper::class));
    app(FinalizeQuizSession::class)->handle($quiz->fresh());

    $ready = test()->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();
    $ready->assertJsonPath('data.actual.case_specific', 1);
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $quiz->getKey())->where('question_category', 'CASE_SPECIFIC')->first();
    expect($snapshot->rule_code)->toBe('R017')->and($snapshot->case_specific_template_id)->toBe('diabetes-cs-r017');
});

test('liver: required kbs values succeed with hepatocellular evidence and a matching case specific template', function () {
    phase3bSeedQuestions(ReportTestCategory::LiverFunction, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::LiverFunction);

    $result = phase3bReadyQuiz($user, $report, $set, 'LIVER_FUNCTION');

    $result['response']->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 1);
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->where('question_category', 'CASE_SPECIFIC')->first();
    expect($snapshot->rule_code)->toBe('LIVER_R001')->and($snapshot->case_specific_template_id)->toBe('liver-cs-r001');
});

test('liver: an isolated alp elevation triggers a different case specific template', function () {
    phase3bSeedQuestions(ReportTestCategory::LiverFunction, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::LiverFunction);

    $payload = phase3bKbsAnalyzePayload('LIVER_FUNCTION', $set->getKey(), $set->version);
    $payload['normalized_results'] = [
        ['source_id' => 23, 'analyte_id' => 'alp', 'display_name' => 'Alkaline Phosphatase', 'value' => 320.0, 'unit' => 'U/L', 'original_value' => 320.0, 'original_unit' => 'U/L', 'reference_range' => ['low' => 44.0, 'high' => 147.0], 'status' => 'high'],
    ];
    $payload['facts'] = [['analyte_id' => 'alp', 'status' => 'high']];
    $payload['conclusions'] = [[
        'code' => 'isolated_alp_elevation', 'level' => 'pattern',
        'title' => ['en' => 'Isolated ALP elevation', 'ar' => null],
        'summary' => ['en' => 'ALP is elevated while other liver markers are not.', 'ar' => null],
        'evidence' => [['source_id' => 23, 'analyte_id' => 'alp', 'label' => 'Alkaline Phosphatase', 'value' => 320.0, 'unit' => 'U/L', 'status' => 'high']],
        'rule_codes' => ['LIVER_R007'],
    ]];
    $payload['rule_traces'] = [[
        'rule_code' => 'LIVER_R007', 'rule_version' => 1, 'fired' => true, 'conditions' => [],
        'evidence' => [['source_id' => 23, 'analyte_id' => 'alp', 'label' => 'Alkaline Phosphatase', 'value' => 320.0, 'unit' => 'U/L', 'status' => 'high']],
        'conclusion_codes' => ['isolated_alp_elevation'],
    ]];

    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);
    $created = test()->withToken(phase3bToken($user))->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])->assertCreated();
    $quiz = QuizSession::query()->findOrFail($created->json('data.id'));
    Http::fake(['*/v1/analyze' => Http::response($payload)]);
    (new ProcessReportAnalysis($quiz->analysis_id))->handle(app(KbsClient::class), app(KbsRequestMapper::class));
    app(FinalizeQuizSession::class)->handle($quiz->fresh());

    $ready = test()->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $quiz->getKey())->where('question_category', 'CASE_SPECIFIC')->first();
    expect($snapshot->rule_code)->toBe('LIVER_R007')->and($snapshot->case_specific_template_id)->toBe('liver-cs-r007');
});

test('kbs service unavailable at quiz creation still produces a general only quiz instead of failing entirely', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);

    Http::fake(['*/v1/metadata' => Http::response(['error' => ['code' => 'unavailable']], 503)]);

    $response = $this->withToken(phase3bToken($user))
        ->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])
        ->assertCreated();

    $response->assertJsonPath('data.status', 'READY')
        ->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 0)
        ->assertJsonPath('data.actual.total', 14);

    expect(Analysis::query()->count())->toBe(0);
});

test('kbs preflight rejecting the input still produces a general only quiz instead of failing entirely', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);

    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response([
            'success' => true, 'blocking' => true, 'ready_for_analysis' => false,
            'issues' => [['code' => 'INVALID_ANALYTE_VALUE', 'analyte_id' => 'hemoglobin', 'message' => ['en' => 'invalid']]],
        ]),
    ]);

    $response = $this->withToken(phase3bToken($user))
        ->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])
        ->assertCreated();

    $response->assertJsonPath('data.status', 'READY')
        ->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 0);
});

test('result locking: the final result stays hidden until the quiz completes, then unlocks', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 2);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    // Locked while the quiz is unanswered.
    $this->withToken(phase3bToken($user))->getJson('/api/v1/analyses/'.$result['analysisId'])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'QUIZ_RESULT_LOCKED');

    $snapshots = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->orderBy('sequence')->get();
    foreach ($snapshots as $index => $snapshot) {
        // Still locked mid-quiz (after the first of two questions).
        if ($index === 1) {
            $this->withToken(phase3bToken($user))->getJson('/api/v1/analyses/'.$result['analysisId'])
                ->assertForbidden()
                ->assertJsonPath('error_code', 'QUIZ_RESULT_LOCKED');
        }
        $this->withToken(phase3bToken($user))->postJson('/api/v1/quiz/'.$result['quizId'].'/answers', [
            'question_snapshot_id' => $snapshot->getKey(),
            'selected_option_id' => $snapshot->correct_option_id,
        ])->assertOk();
    }

    expect(QuizSession::query()->findOrFail($result['quizId'])->status)->toBe(QuizSessionStatus::Completed);

    // Unlocked after completion, and the real persisted evidence is visible.
    $unlocked = $this->withToken(phase3bToken($user))->getJson('/api/v1/analyses/'.$result['analysisId'])->assertOk();
    $unlocked->assertJsonPath('data.status', 'SUCCEEDED')
        ->assertJsonPath('data.flow', 'quiz-first')
        ->assertJsonPath('data.result.conclusions.0.rule_codes.0', 'R001');
});

test('result locking never affects a direct result analysis for the same report', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 2);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);
    // Start (and leave incomplete) a quiz-first analysis for this report/version.
    phase3bReadyQuiz($user, $report, $set, 'CBC');

    // The escape-hatch / direct-result flow is a completely separate Analysis (a
    // different identity_key because flow differs) and must remain immediately
    // available, exactly as in Phase 3A, regardless of the incomplete quiz above.
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);
    $direct = $this->withToken(phase3bToken($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => 'direct-result',
    ])->assertAccepted();
    $directAnalysisId = $direct->json('data.id');

    Http::fake(['*/v1/analyze' => Http::response(phase3bKbsAnalyzePayload('CBC', $set->getKey(), $set->version))]);
    (new ProcessReportAnalysis($directAnalysisId))->handle(app(KbsClient::class), app(KbsRequestMapper::class));

    $this->withToken(phase3bToken($user))->getJson('/api/v1/analyses/'.$directAnalysisId)
        ->assertOk()
        ->assertJsonPath('data.flow', 'direct-result');
});

test('the public analyze endpoint still rejects flow quiz first exactly as in phase 3a', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 2);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);
    Queue::fake();
    Http::preventStrayRequests();

    $this->withToken(phase3bToken($user))->postJson('/api/v1/reports/'.$report->getKey().'/analyze', [
        'verified_result_set_id' => $set->getKey(), 'flow' => 'quiz-first',
    ])->assertConflict()->assertJsonPath('error_code', 'ANALYSIS_NOT_PROCESSABLE');

    expect(Analysis::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a quiz session left preparing is finalized asynchronously once the queued analysis job completes', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);

    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);
    $created = $this->withToken(phase3bToken($user))->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])->assertCreated();
    $created->assertJsonPath('data.status', 'PREPARING');
    $quiz = QuizSession::query()->findOrFail($created->json('data.id'));

    // Still preparing while polled before the analysis job has run.
    $this->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())
        ->assertOk()->assertJsonPath('data.status', 'PREPARING')->assertJsonCount(0, 'data.questions');

    Http::fake(['*/v1/analyze' => Http::response(phase3bKbsAnalyzePayload('CBC', $set->getKey(), $set->version))]);
    (new ProcessReportAnalysis($quiz->analysis_id))->handle(app(KbsClient::class), app(KbsRequestMapper::class));
    // ProcessReportAnalysis dispatches FinalizeQuizPreparation via afterCommit, which
    // Queue::fake() captured instead of running — assert it was actually queued, then
    // run it exactly as the real queue worker would.
    Queue::assertPushed(FinalizeQuizPreparation::class, 1);
    (new FinalizeQuizPreparation($quiz->getKey()))->handle(app(FinalizeQuizSession::class));

    $this->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())
        ->assertOk()->assertJsonPath('data.status', 'READY')->assertJsonPath('data.actual.general', 14);
});
