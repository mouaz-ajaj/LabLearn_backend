<?php

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\PatientSex;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Jobs\ProcessReportAnalysis;
use App\Models\Analysis;
use App\Models\AnalysisConclusion;
use App\Models\QuizQuestionSnapshot;
use App\Models\QuizSession;
use App\Models\Report;
use App\Models\RuleTrace;
use App\Models\User;
use App\Models\VerifiedResult;
use App\Models\VerifiedResultSet;
use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsRequestMapper;
use App\Services\Quiz\CaseSpecificQuestionProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Reuses global Pest helpers already defined elsewhere in this suite and available
// across the whole run: phase3Fixture()/phase3Token()/phase3AnalysisPayload() from
// tests/Feature/Phase3/AnalysisApiTest.php, and phase3bFixture()/phase3bToken()/
// phase3bSeedQuestions()/phase3bReadyQuiz()/FakeQuizCaseSpecificQuestionProvider from
// tests/Feature/Phase3B/QuizApiTest.php.

function phase4bRunDirectAnalysis(Report $report, VerifiedResultSet $set, User $user, ReportTestCategory $category = ReportTestCategory::Cbc): Analysis
{
    $analysis = Analysis::query()->create([
        'report_id' => $report->getKey(), 'verified_result_set_id' => $set->getKey(), 'verified_result_set_version' => $set->version,
        'user_id' => $user->getKey(), 'report_category' => $category->value, 'status' => AnalysisStatus::Queued,
        'flow' => AnalysisFlow::DirectResult, 'identity_key' => hash('sha256', fake()->uuid()),
        'schema_version' => 1, 'input_schema_version' => 1, 'engine_version' => '1.0.0',
        'ruleset_version' => '2026.07.24.2', 'catalog_version' => '2026.07.24.2',
    ]);
    Http::fake(['*/v1/analyze' => Http::response(phase3AnalysisPayload($analysis))]);
    (new ProcessReportAnalysis($analysis->getKey()))->handle(app(KbsClient::class), app(KbsRequestMapper::class));

    return $analysis->refresh();
}

/** @param array{quizId: int, analysisId: int} $readyQuiz */
function phase4bCompleteQuiz(User $user, array $readyQuiz): void
{
    $snapshots = QuizQuestionSnapshot::query()->where('quiz_session_id', $readyQuiz['quizId'])->orderBy('sequence')->get();
    foreach ($snapshots as $snapshot) {
        test()->withToken(phase3bToken($user))->postJson('/api/v1/quiz/'.$readyQuiz['quizId'].'/answers', [
            'question_snapshot_id' => $snapshot->getKey(),
            'selected_option_id' => $snapshot->correct_option_id,
        ])->assertOk();
    }
}

test('report details requires authentication', function () {
    $this->getJson('/api/v1/reports/1')->assertUnauthorized()->assertJsonPath('error_code', 'UNAUTHENTICATED');
});

test('the owner can view their own report details', function () {
    [$user, $report] = phase3Fixture();

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())
        ->assertOk()
        ->assertJsonPath('data.report.id', $report->getKey())
        ->assertJsonPath('data.report.test_category', 'CBC')
        ->assertJsonPath('data.report.status', 'VERIFIED');
});

test('another user is forbidden from viewing someone elses report, matching existing ownership convention', function () {
    [, $report] = phase3Fixture();
    $other = User::factory()->create();

    $this->withToken(phase3Token($other))->getJson('/api/v1/reports/'.$report->getKey())
        ->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
});

test('a nonexistent report id returns not found', function () {
    [$user] = phase3Fixture();

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/999999')->assertNotFound();
});

test('a soft deleted report is not accessible even to its owner', function () {
    [$user, $report] = phase3Fixture();
    $report->delete();

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertNotFound();
});

test('a report with no verification yet returns metadata only', function () {
    $user = User::factory()->create();
    $report = Report::factory()->for($user)->create(['status' => ReportStatus::Processing]);

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())
        ->assertOk()
        ->assertJsonPath('data.report.status', 'PROCESSING')
        ->assertJsonPath('data.verification', null)
        ->assertJsonPath('data.analysis', null);
});

test('a verified report with no analysis yet shows verification but no fabricated analysis', function () {
    [$user, $report] = phase3Fixture();

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())
        ->assertOk()
        ->assertJsonPath('data.verification.version', 1)
        ->assertJsonPath('data.verification.values.0.label', 'HGB')
        ->assertJsonPath('data.analysis', null)
        ->assertJsonMissingPath('data.verification.values.0.original_confidence')
        ->assertJsonMissingPath('data.verification.values.0.source_extracted_result_id');
});

test('a completed report shows the full stored analysis with conclusions and rule traces', function () {
    [$user, $report, $set] = phase3Fixture();
    $analysis = phase4bRunDirectAnalysis($report, $set, $user);

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())
        ->assertOk()
        ->assertJsonPath('data.analysis.id', $analysis->getKey())
        ->assertJsonPath('data.analysis.status', 'SUCCEEDED')
        ->assertJsonPath('data.analysis.result.conclusions.0.code', 'possible_anemia_pattern')
        ->assertJsonPath('data.analysis.result.rule_traces.0.rule_code', 'cbc_low_hemoglobin')
        ->assertJsonPath('data.analysis.result.verified_results.0.label', 'HGB');
});

test('a failed analysis is shown honestly with safe error information, not fabricated as succeeded', function () {
    config(['kbs.retry_attempts' => 1]);
    [$user, $report, $set] = phase3Fixture();
    $analysis = Analysis::query()->create([
        'report_id' => $report->getKey(), 'verified_result_set_id' => $set->getKey(), 'verified_result_set_version' => 1,
        'user_id' => $user->getKey(), 'report_category' => 'CBC', 'status' => AnalysisStatus::Queued,
        'flow' => AnalysisFlow::DirectResult, 'identity_key' => hash('sha256', fake()->uuid()),
        'ruleset_version' => '2026.07.24.2',
    ]);
    Http::fake(['*/v1/analyze' => Http::response(['error' => ['code' => 'INVALID_ANALYTE_VALUE']], 422)]);
    (new ProcessReportAnalysis($analysis->getKey()))->handle(app(KbsClient::class), app(KbsRequestMapper::class));

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())
        ->assertOk()
        ->assertJsonPath('data.analysis.status', 'FAILED')
        ->assertJsonPath('data.analysis.error.code', 'INVALID_ANALYTE_VALUE')
        ->assertJsonPath('data.analysis.result', null);
});

test('a newer verification version never gets paired with an older versions analysis', function () {
    [$user, $report, $setV1] = phase3Fixture();
    phase4bRunDirectAnalysis($report, $setV1, $user);

    $setV2 = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 2, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 24, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'phase4b-v2-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => 'MATCH', 'category_gate_category' => 'CBC',
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);
    $setV2->results()->createMany([
        ['label' => 'HGB', 'value' => '13.0', 'unit' => 'g/dL', 'reference_range' => '12-16', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1],
    ]);

    $response = $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertOk();

    expect($response->json('data.verification.version'))->toBe(2)
        ->and($response->json('data.verification.values.0.value'))->toBe('13.0')
        ->and($response->json('data.analysis'))->toBeNull();
});

test('when the latest version has its own analysis, it is shown instead of an older versions analysis', function () {
    [$user, $report, $setV1] = phase3Fixture();
    phase4bRunDirectAnalysis($report, $setV1, $user);

    $setV2 = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 2, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 24, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'phase4b-v2b-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => 'MATCH', 'category_gate_category' => 'CBC',
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);
    $setV2->results()->createMany([
        ['label' => 'HGB', 'value' => '13.0', 'unit' => 'g/dL', 'reference_range' => '12-16', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1],
    ]);
    $analysisV2 = phase4bRunDirectAnalysis($report, $setV2->fresh(), $user);

    $response = $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertOk();

    expect($response->json('data.analysis.id'))->toBe($analysisV2->getKey())
        ->and($response->json('data.analysis.verified_result_set_version'))->toBe(2);
});

test('a succeeded direct result analysis is preferred over a succeeded quiz first analysis for the same version', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set] = phase3bFixture();
    $quiz = phase3bReadyQuiz($user, $report, $set, 'CBC');
    phase4bCompleteQuiz($user, $quiz);

    $directAnalysis = phase4bRunDirectAnalysis($report, $set->fresh(), $user);

    $response = $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertOk();

    expect($response->json('data.analysis.id'))->toBe($directAnalysis->getKey())
        ->and($response->json('data.analysis.flow'))->toBe('direct-result')
        ->and($directAnalysis->getKey())->not->toBe((int) $quiz['analysisId']);
});

test('a pending quiz first analysis is never shown as the historical result until its quiz completes', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 5);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report] = phase3bFixture();
    $quiz = phase3bReadyQuiz($user, $report, $report->verifiedResultSets()->latest('version')->first(), 'CBC');

    $pending = $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertOk();
    expect($pending->json('data.analysis'))->toBeNull();

    phase4bCompleteQuiz($user, $quiz);

    $completed = $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertOk();
    expect($completed->json('data.analysis.id'))->toBe((int) $quiz['analysisId'])
        ->and($completed->json('data.analysis.flow'))->toBe('quiz-first');
});

test('quiz summary is included only once the quiz is completed and reflects the real score', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 5);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report] = phase3bFixture();
    $quiz = phase3bReadyQuiz($user, $report, $report->verifiedResultSets()->latest('version')->first(), 'CBC');

    $pending = $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertOk();
    expect($pending->json('data.quiz_summary'))->toBeNull();

    phase4bCompleteQuiz($user, $quiz);

    $completed = $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertOk();
    expect($completed->json('data.quiz_summary.status'))->toBe('COMPLETED')
        ->and($completed->json('data.quiz_summary.total'))->toBe(5)
        ->and($completed->json('data.quiz_summary.score'))->toBe(5);
});

test('the response contract matches the documented shape', function () {
    [$user, $report, $set] = phase3Fixture();
    phase4bRunDirectAnalysis($report, $set, $user);

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())
        ->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'report' => ['id', 'test_category', 'source_type', 'status', 'report_date', 'created_at', 'updated_at'],
                'verification' => ['id', 'version', 'patient_age_years', 'patient_sex', 'confirmed_at', 'values'],
                'analysis' => ['id', 'status', 'flow', 'result' => ['conclusions', 'rule_traces', 'verified_results']],
                'quiz_summary',
            ],
        ]);
});

test('report details performs no writes and dispatches no jobs - critical no side effect regression test', function () {
    [$user, $report, $set] = phase3Fixture();
    phase4bRunDirectAnalysis($report, $set, $user);
    Queue::fake();

    $counts = [
        'reports' => Report::query()->count(),
        'verified_result_sets' => VerifiedResultSet::query()->count(),
        'verified_results' => VerifiedResult::query()->count(),
        'analyses' => Analysis::query()->count(),
        'analysis_conclusions' => AnalysisConclusion::query()->count(),
        'rule_traces' => RuleTrace::query()->count(),
        'quiz_sessions' => QuizSession::query()->count(),
    ];

    $this->withToken(phase3Token($user))->getJson('/api/v1/reports/'.$report->getKey())->assertOk();

    expect(Report::query()->count())->toBe($counts['reports'])
        ->and(VerifiedResultSet::query()->count())->toBe($counts['verified_result_sets'])
        ->and(VerifiedResult::query()->count())->toBe($counts['verified_results'])
        ->and(Analysis::query()->count())->toBe($counts['analyses'])
        ->and(AnalysisConclusion::query()->count())->toBe($counts['analysis_conclusions'])
        ->and(RuleTrace::query()->count())->toBe($counts['rule_traces'])
        ->and(QuizSession::query()->count())->toBe($counts['quiz_sessions']);
    Queue::assertNothingPushed();
});
