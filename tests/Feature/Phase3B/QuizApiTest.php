<?php

use App\Enums\PatientSex;
use App\Enums\QuizQuestionCategory;
use App\Enums\QuizSessionStatus;
use App\Enums\ReportSourceType;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Jobs\ProcessReportAnalysis;
use App\Models\Analysis;
use App\Models\Question;
use App\Models\QuizQuestionSnapshot;
use App\Models\QuizSession;
use App\Models\Report;
use App\Models\StudentAnswer;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsRequestMapper;
use App\Services\Quiz\CaseSpecificQuestionProvider;
use App\Services\Quiz\FinalizeQuizSession;
use App\Services\Quiz\StartQuizSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Test-only stand-in for the real Phase 3B.3 KBS-driven provider. Lets a handful of
 * tests prove the quiz domain (target vs actual counts, snapshotting, completion,
 * scoring) accepts an arbitrary Case-Specific count without any further schema/service
 * changes, independent of the real CaseSpecificQuestionBuilder/template catalog tested
 * separately in QuizKbsIntegrationTest.php.
 */
class FakeQuizCaseSpecificQuestionProvider implements CaseSpecificQuestionProvider
{
    public function __construct(private readonly int $count) {}

    public function provide(Report $report, VerifiedResultSet $set, ?Analysis $analysis, int $limit): Collection
    {
        $n = min($this->count, $limit);
        $items = [];
        for ($i = 1; $i <= $n; $i++) {
            $items[] = [
                'question_text' => ['en' => "[TEST FIXTURE] Case-specific question {$i}", 'ar' => "[بيانات تجريبية] سؤال {$i}"],
                'options' => [
                    'x' => ['en' => 'Option X', 'ar' => 'خيار X'],
                    'y' => ['en' => 'Option Y', 'ar' => 'خيار Y'],
                ],
                'option_order' => ['x', 'y'],
                'correct_option_id' => 'x',
                'explanation' => ['en' => 'Test fixture explanation.', 'ar' => 'شرح تجريبي.'],
                'case_specific_template_id' => 'test-template-'.$i,
                'case_specific_template_version' => 1,
                'evidence' => [['label' => 'HGB', 'value' => '10.2', 'unit' => 'g/dL']],
                'rule_code' => 'test_rule_'.$i,
                'analyte_refs' => ['hemoglobin'],
            ];
        }

        return collect($items);
    }
}

function phase3bFixture(?User $user = null, string $gate = 'MATCH', ReportTestCategory $category = ReportTestCategory::Cbc): array
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
        'idempotency_key' => 'phase3b-fixture-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => $gate, 'category_gate_category' => $gate === 'MATCH' ? $category->value : null,
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);
    $set->results()->createMany([
        ['label' => 'HGB', 'value' => '9.5', 'unit' => 'g/dL', 'reference_range' => '12-16', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1],
        ['label' => 'MCV', 'value' => '72', 'unit' => 'fL', 'reference_range' => '80-100', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 2],
    ]);

    return [$user, $report, $set->fresh('results')];
}

function phase3bToken(User $user): string
{
    return $user->createToken('phase3b-test')->plainTextToken;
}

function phase3bSeedQuestions(ReportTestCategory $category, int $count, bool $active = true): void
{
    Question::factory()->count($count)->forCategory($category)->state(['active' => $active])->create();
}

/** Real KBS /v1/metadata response — reused verbatim from Phase 3A's own fixture. */
function phase3bKbsMetadata(): array
{
    return [
        'input_schema_version' => '1',
        'output_schema_version' => '1',
        'engine_version' => '1.0.2',
        'ruleset_version' => '2026.07.24.2',
        'analyte_catalog_version' => '2026.07.24.2',
        'knowledge_base_version' => '2026.07.24.2',
        'supported_categories' => ['CBC', 'DIABETES', 'LIVER_FUNCTION'],
    ];
}

function phase3bKbsValidateClean(): array
{
    return ['success' => true, 'blocking' => false, 'ready_for_analysis' => true, 'issues' => []];
}

/**
 * Real, captured (not invented) KBS /v1/analyze responses for CBC (HGB low + MCV low),
 * DIABETES (HbA1c in the diabetes range), and LIVER_FUNCTION (ALT high with an R-ratio
 * above 5) — verified against the actual kbs/core engine (not the mocked Laravel
 * boundary) via a standalone probe before writing these tests, so the fixtures below
 * are exactly what the real engine currently returns for this input, not assumed.
 */
function phase3bKbsAnalyzePayload(string $category, int $verifiedResultSetId = 41, int $verifiedResultSetVersion = 1): array
{
    $payload = match ($category) {
        'CBC' => [
            'success' => true, 'schema_version' => '1', 'input_schema_version' => '1', 'output_schema_version' => '1',
            'engine_version' => '1.0.2', 'ruleset_version' => '2026.07.24.2', 'analyte_catalog_version' => '2026.07.24.2',
            'knowledge_base_version' => '2026.07.24.2', 'request_id' => 'test-cbc', 'category' => 'CBC',
            'verified_result_set' => ['id' => 41, 'version' => 1], 'status' => 'patterns_detected',
            'category_validation' => ['status' => 'MATCH', 'matched_analytes' => ['hemoglobin', 'mcv'], 'other_category_analytes' => [], 'unsupported_analytes' => [], 'missing_required_evidence' => [], 'reason' => 'SUPPORTED_CATEGORY_EVIDENCE'],
            'normalized_results' => [
                ['source_id' => 1, 'analyte_id' => 'hemoglobin', 'display_name' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'original_value' => 9.5, 'original_unit' => 'g/dL', 'reference_range' => ['low' => 12.0, 'high' => 16.0], 'status' => 'low'],
                ['source_id' => 2, 'analyte_id' => 'mcv', 'display_name' => 'Mean Corpuscular Volume', 'value' => 72.0, 'unit' => 'fL', 'original_value' => 72.0, 'original_unit' => 'fL', 'reference_range' => ['low' => 80.0, 'high' => 100.0], 'status' => 'low'],
            ],
            'facts' => [['analyte_id' => 'hemoglobin', 'status' => 'low'], ['analyte_id' => 'mcv', 'status' => 'low']],
            'conclusions' => [
                ['code' => 'possible_anemia_pattern', 'level' => 'pattern', 'title' => ['en' => 'Possible anemia pattern', 'ar' => null], 'summary' => ['en' => 'Low hemoglobin, hematocrit, or RBC may suggest an anemia pattern.', 'ar' => null], 'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']], 'rule_codes' => ['R001']],
                ['code' => 'microcytic_anemia_pattern', 'level' => 'pattern', 'title' => ['en' => 'Possible microcytic anemia pattern', 'ar' => null], 'summary' => ['en' => 'Anemia with low MCV suggests small red blood cells.', 'ar' => null], 'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low'], ['source_id' => 2, 'analyte_id' => 'mcv', 'label' => 'Mean Corpuscular Volume', 'value' => 72.0, 'unit' => 'fL', 'status' => 'low']], 'rule_codes' => ['R002']],
            ],
            'rule_traces' => [
                ['rule_code' => 'R001', 'rule_version' => 1, 'fired' => true, 'conditions' => [], 'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']], 'conclusion_codes' => ['possible_anemia_pattern']],
                ['rule_code' => 'R002', 'rule_version' => 1, 'fired' => true, 'conditions' => [], 'evidence' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low'], ['source_id' => 2, 'analyte_id' => 'mcv', 'label' => 'Mean Corpuscular Volume', 'value' => 72.0, 'unit' => 'fL', 'status' => 'low']], 'conclusion_codes' => ['microcytic_anemia_pattern']],
            ],
            'missing_information' => [], 'warnings' => [],
            'summary' => ['en' => 'Possible educational laboratory patterns were detected.', 'ar' => null],
            'disclaimer' => ['en' => 'Educational decision support only.', 'ar' => null],
        ],
        'DIABETES' => [
            'success' => true, 'schema_version' => '1', 'input_schema_version' => '1', 'output_schema_version' => '1',
            'engine_version' => '1.0.2', 'ruleset_version' => '2026.07.24.2', 'analyte_catalog_version' => '2026.07.24.2',
            'knowledge_base_version' => '2026.07.24.2', 'request_id' => 'test-diabetes', 'category' => 'DIABETES',
            'verified_result_set' => ['id' => 41, 'version' => 1], 'status' => 'patterns_detected',
            'category_validation' => ['status' => 'MATCH', 'matched_analytes' => ['hba1c'], 'other_category_analytes' => [], 'unsupported_analytes' => [], 'missing_required_evidence' => [], 'reason' => 'SUPPORTED_CATEGORY_EVIDENCE'],
            'normalized_results' => [
                ['source_id' => 11, 'analyte_id' => 'hba1c', 'display_name' => 'Hemoglobin A1c', 'value' => 6.7, 'unit' => '%', 'original_value' => 6.7, 'original_unit' => '%', 'reference_range' => ['low' => 4.0, 'high' => 5.6], 'status' => 'diabetes'],
            ],
            'facts' => [['analyte_id' => 'hba1c', 'status' => 'diabetes']],
            'conclusions' => [
                ['code' => 'possible_diabetes_pattern', 'level' => 'pattern', 'title' => ['en' => 'Possible diabetes pattern', 'ar' => null], 'summary' => ['en' => 'Glucose or HbA1c may meet common diabetes thresholds; confirmation is required.', 'ar' => null], 'evidence' => [['source_id' => 11, 'analyte_id' => 'hba1c', 'label' => 'Hemoglobin A1c', 'value' => 6.7, 'unit' => '%', 'status' => 'diabetes']], 'rule_codes' => ['R020']],
            ],
            'rule_traces' => [
                ['rule_code' => 'R020', 'rule_version' => 2, 'fired' => true, 'conditions' => [], 'evidence' => [['source_id' => 11, 'analyte_id' => 'hba1c', 'label' => 'Hemoglobin A1c', 'value' => 6.7, 'unit' => '%', 'status' => 'diabetes']], 'conclusion_codes' => ['possible_diabetes_pattern']],
            ],
            'missing_information' => [], 'warnings' => [],
            'summary' => ['en' => 'Possible educational laboratory patterns were detected.', 'ar' => null],
            'disclaimer' => ['en' => 'Educational decision support only.', 'ar' => null],
        ],
        'LIVER_FUNCTION' => [
            'success' => true, 'schema_version' => '1', 'input_schema_version' => '1', 'output_schema_version' => '1',
            'engine_version' => '1.0.2', 'ruleset_version' => '2026.07.24.2', 'analyte_catalog_version' => '2026.07.24.2',
            'knowledge_base_version' => '2026.07.24.2', 'request_id' => 'test-liver', 'category' => 'LIVER_FUNCTION',
            'verified_result_set' => ['id' => 41, 'version' => 1], 'status' => 'patterns_detected',
            'category_validation' => ['status' => 'MATCH', 'matched_analytes' => ['albumin', 'alp', 'alt', 'ast', 'total_bilirubin'], 'other_category_analytes' => [], 'unsupported_analytes' => [], 'missing_required_evidence' => [], 'reason' => 'SUPPORTED_CATEGORY_EVIDENCE'],
            'normalized_results' => [
                ['source_id' => 21, 'analyte_id' => 'alt', 'display_name' => 'Alanine Aminotransferase', 'value' => 200.0, 'unit' => 'U/L', 'original_value' => 200.0, 'original_unit' => 'U/L', 'reference_range' => ['low' => 7.0, 'high' => 56.0], 'status' => 'high'],
                ['source_id' => 22, 'analyte_id' => 'ast', 'display_name' => 'Aspartate Aminotransferase', 'value' => 100.0, 'unit' => 'U/L', 'original_value' => 100.0, 'original_unit' => 'U/L', 'reference_range' => ['low' => 10.0, 'high' => 40.0], 'status' => 'high'],
                ['source_id' => 23, 'analyte_id' => 'alp', 'display_name' => 'Alkaline Phosphatase', 'value' => 100.0, 'unit' => 'U/L', 'original_value' => 100.0, 'original_unit' => 'U/L', 'reference_range' => ['low' => 44.0, 'high' => 147.0], 'status' => 'normal'],
                ['source_id' => 24, 'analyte_id' => 'total_bilirubin', 'display_name' => 'Total Bilirubin', 'value' => 1.0, 'unit' => 'mg/dL', 'original_value' => 1.0, 'original_unit' => 'mg/dL', 'reference_range' => ['low' => 0.1, 'high' => 1.2], 'status' => 'normal'],
                ['source_id' => 25, 'analyte_id' => 'albumin', 'display_name' => 'Albumin', 'value' => 4.0, 'unit' => 'g/dL', 'original_value' => 4.0, 'original_unit' => 'g/dL', 'reference_range' => ['low' => 3.5, 'high' => 5.5], 'status' => 'normal'],
            ],
            'facts' => [
                ['analyte_id' => 'albumin', 'status' => 'normal'], ['analyte_id' => 'alp', 'status' => 'normal'],
                ['analyte_id' => 'alt', 'status' => 'high'], ['analyte_id' => 'ast', 'status' => 'high'],
                ['analyte_id' => 'total_bilirubin', 'status' => 'normal'],
            ],
            'conclusions' => [
                ['code' => 'hepatocellular_injury_pattern', 'level' => 'pattern', 'title' => ['en' => 'Possible hepatocellular injury pattern', 'ar' => 'نمط أذية خلوية كبدية محتمل'], 'summary' => ['en' => 'ALT/AST elevation predominates over ALP after normalization to laboratory upper limits.', 'ar' => null], 'evidence' => [['source_id' => 23, 'analyte_id' => 'alp', 'label' => 'Alkaline Phosphatase', 'value' => 100.0, 'unit' => 'U/L', 'status' => 'normal'], ['source_id' => 21, 'analyte_id' => 'alt', 'label' => 'Alanine Aminotransferase', 'value' => 200.0, 'unit' => 'U/L', 'status' => 'high']], 'rule_codes' => ['LIVER_R001']],
            ],
            'rule_traces' => [
                ['rule_code' => 'LIVER_R001', 'rule_version' => 2, 'fired' => true, 'conditions' => [], 'evidence' => [['source_id' => 23, 'analyte_id' => 'alp', 'label' => 'Alkaline Phosphatase', 'value' => 100.0, 'unit' => 'U/L', 'status' => 'normal'], ['source_id' => 21, 'analyte_id' => 'alt', 'label' => 'Alanine Aminotransferase', 'value' => 200.0, 'unit' => 'U/L', 'status' => 'high']], 'conclusion_codes' => ['hepatocellular_injury_pattern']],
            ],
            'missing_information' => [], 'warnings' => [],
            'summary' => ['en' => 'Possible educational laboratory patterns were detected.', 'ar' => null],
            'disclaimer' => ['en' => 'Educational decision support only.', 'ar' => null],
        ],
        default => throw new InvalidArgumentException("No KBS fixture for category {$category}"),
    };
    $payload['verified_result_set'] = ['id' => $verifiedResultSetId, 'version' => $verifiedResultSetVersion];

    return $payload;
}

/**
 * Drives a quiz-first session all the way to READY through the real orchestration:
 * create (PREPARING, internal Analysis QUEUED under Queue::fake), manually run the
 * Analysis job (as AnalysisApiTest already does for its own job-level assertions),
 * then manually run the finalize step that ProcessReportAnalysis's afterCommit dispatch
 * would have triggered had the queue not been faked. Returns the quiz id and the final
 * GET /quiz/{id} response.
 */
function phase3bReadyQuiz(User $user, Report $report, VerifiedResultSet $set, string $category = 'CBC'): array
{
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);

    $created = test()->withToken(phase3bToken($user))
        ->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])
        ->assertCreated();
    $quizId = (int) $created->json('data.id');
    $quiz = QuizSession::query()->findOrFail($quizId);

    Http::fake(['*/v1/analyze' => Http::response(phase3bKbsAnalyzePayload($category, $set->getKey(), $set->version))]);
    (new ProcessReportAnalysis($quiz->analysis_id))->handle(app(KbsClient::class), app(KbsRequestMapper::class));

    app(FinalizeQuizSession::class)->handle($quiz->fresh());

    $ready = test()->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$quizId)->assertOk();

    return ['quizId' => $quizId, 'analysisId' => $quiz->analysis_id, 'response' => $ready];
}

/**
 * Same end result as phase3bReadyQuiz(), but built entirely through direct service
 * calls, never via an authenticated HTTP request. Use this for the "other side" of a
 * cross-user test (e.g. building Student B's own quiz while the test's real HTTP
 * assertions authenticate only as Student A) — Sanctum's guard caches the resolved
 * user for the whole test method, so mixing withToken() calls for two different users
 * in one test misattributes later requests to whichever user resolved first.
 */
function phase3bReadyQuizViaService(User $user, Report $report, VerifiedResultSet $set, string $category = 'CBC'): QuizSession
{
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
        '*/v1/analyze' => Http::response(phase3bKbsAnalyzePayload($category, $set->getKey(), $set->version)),
    ]);

    $quiz = app(StartQuizSession::class)->handle($report, $set, $user);

    return $quiz->fresh();
}

test('student can create a quiz session that stays preparing until the internal kbs analysis lands', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture();

    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);

    $response = $this->withToken(phase3bToken($user))
        ->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])
        ->assertCreated();

    $response->assertJsonPath('data.status', 'PREPARING')
        ->assertJsonPath('data.actual.total', 0);

    $quiz = QuizSession::query()->findOrFail($response->json('data.id'));
    expect($quiz->analysis_id)->not->toBeNull();
    $analysis = Analysis::query()->findOrFail($quiz->analysis_id);
    expect($analysis->flow->value)->toBe('quiz-first')
        ->and($analysis->status->value)->toBe('QUEUED');
    Queue::assertPushed(ProcessReportAnalysis::class, 1);
});

test('the quiz becomes ready with real general and case specific questions once kbs completes', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture();

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.status', 'READY')
        ->assertJsonPath('data.target.general', 14)
        ->assertJsonPath('data.target.case_specific', 6)
        ->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 2)
        ->assertJsonPath('data.actual.total', 16)
        ->assertJsonCount(16, 'data.questions');

    $caseSpecific = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->where('question_category', QuizQuestionCategory::CaseSpecific->value)->get();
    expect($caseSpecific)->toHaveCount(2)
        ->and($caseSpecific->pluck('rule_code')->sort()->values()->all())->toBe(['R001', 'R002']);
});

test('regular user is forbidden from creating a quiz', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture(User::factory()->regular()->create());

    $this->withToken(phase3bToken($user))
        ->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'QUIZ_STUDENT_ONLY');

    expect(QuizSession::query()->count())->toBe(0);
});

test('unauthenticated request is rejected', function () {
    [$user, $report, $set] = phase3bFixture();

    $this->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])
        ->assertUnauthorized();
});

test('student a cannot create a quiz for student bs report', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$owner, $report, $set] = phase3bFixture();
    $other = User::factory()->student('3')->create();

    $this->withToken(phase3bToken($other))
        ->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])
        ->assertForbidden();

    expect(QuizSession::query()->count())->toBe(0);
});

test('category gate mismatch blocks quiz creation', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture(gate: 'MISMATCH');

    $this->withToken(phase3bToken($user))
        ->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])
        ->assertConflict()
        ->assertJsonPath('error_code', 'CATEGORY_GATE_NOT_MATCHED');

    expect(QuizSession::query()->count())->toBe(0);
});

test('repeated creation is idempotent and does not duplicate the internal analysis', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture();
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);
    $uri = '/api/v1/reports/'.$report->getKey().'/quiz';
    $payload = ['verified_result_set_id' => $set->getKey()];

    $first = $this->withToken(phase3bToken($user))->postJson($uri, $payload)->assertCreated();
    $second = $this->withToken(phase3bToken($user))->postJson($uri, $payload)->assertOk();
    $third = $this->withToken(phase3bToken($user))->postJson($uri, $payload)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($third->json('data.id'))->toBe($first->json('data.id'))
        ->and(QuizSession::query()->count())->toBe(1)
        ->and(Analysis::query()->count())->toBe(1);
    Queue::assertPushed(ProcessReportAnalysis::class, 1);
});

test('ready quiz questions are byte identical across repeated fetches and do not reshuffle', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $again = $this->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$result['quizId'])->assertOk();

    expect(collect($again->json('data.questions'))->pluck('id')->all())
        ->toBe(collect($result['response']->json('data.questions'))->pluck('id')->all());
});

test('cbc quiz never receives diabetes or liver function general questions', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    phase3bSeedQuestions(ReportTestCategory::Diabetes, 14);
    phase3bSeedQuestions(ReportTestCategory::LiverFunction, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Cbc);

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');
    $result['response']->assertJsonPath('data.actual.general', 14);

    $sourceQuestionIds = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->whereNotNull('source_question_id')->pluck('source_question_id');
    $categories = Question::query()->whereIn('id', $sourceQuestionIds)->pluck('category')->map(fn ($c) => $c->value)->unique()->values();

    expect($categories->all())->toBe(['CBC']);
});

test('a smaller eligible general question bank produces a smaller quiz instead of padding or duplicating', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 5);
    [$user, $report, $set] = phase3bFixture();

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.target.general', 14)
        ->assertJsonPath('data.actual.general', 5);

    $sourceQuestionIds = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->where('question_category', QuizQuestionCategory::General->value)->pluck('source_question_id');
    expect($sourceQuestionIds->unique()->count())->toBe(5);
});

test('inactive questions are never selected', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 3, active: true);
    phase3bSeedQuestions(ReportTestCategory::Cbc, 20, active: false);
    [$user, $report, $set] = phase3bFixture();

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.actual.general', 3);
});

test('case specific provider allows a full 20 question session without a hard coded count', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(6));
    [$user, $report, $set] = phase3bFixture();

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.actual.total', 20)
        ->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 6);
});

test('an 18 question session is accepted when only 4 case specific questions are available', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(4));
    [$user, $report, $set] = phase3bFixture();

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.target.total', 20)
        ->assertJsonPath('data.actual.total', 18);
});

test('a 16 question session is accepted when only 2 case specific questions are available', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(2));
    [$user, $report, $set] = phase3bFixture();

    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $result['response']->assertJsonPath('data.actual.total', 16);
});

test('a general only quiz is accepted when the internal analysis has no matching case specific templates', function () {
    phase3bSeedQuestions(ReportTestCategory::Diabetes, 14);
    [$user, $report, $set] = phase3bFixture(category: ReportTestCategory::Diabetes);

    // Real DIABETES payload but with an unrelated/no-op conclusion set (simulates a
    // report whose evidence matches no catalog template) — General still succeeds.
    $payload = phase3bKbsAnalyzePayload('DIABETES', $set->getKey(), $set->version);
    $payload['conclusions'] = [];
    $payload['rule_traces'] = [];
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);
    $created = $this->withToken(phase3bToken($user))->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])->assertCreated();
    $quiz = QuizSession::query()->findOrFail($created->json('data.id'));
    Http::fake(['*/v1/analyze' => Http::response($payload)]);
    (new ProcessReportAnalysis($quiz->analysis_id))->handle(app(KbsClient::class), app(KbsRequestMapper::class));
    app(FinalizeQuizSession::class)->handle($quiz->fresh());

    $ready = $this->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();
    $ready->assertJsonPath('data.status', 'READY')
        ->assertJsonPath('data.actual.general', 14)
        ->assertJsonPath('data.actual.case_specific', 0);
});

test('a report with zero eligible general questions and zero case specific evidence fails without fabricating content', function () {
    [$user, $report, $set] = phase3bFixture();

    $payload = phase3bKbsAnalyzePayload('CBC', $set->getKey(), $set->version);
    $payload['conclusions'] = [];
    $payload['rule_traces'] = [];
    Queue::fake();
    Http::fake([
        '*/v1/metadata' => Http::response(phase3bKbsMetadata()),
        '*/v1/validate' => Http::response(phase3bKbsValidateClean()),
    ]);
    $created = $this->withToken(phase3bToken($user))->postJson('/api/v1/reports/'.$report->getKey().'/quiz', ['verified_result_set_id' => $set->getKey()])->assertCreated();
    $quiz = QuizSession::query()->findOrFail($created->json('data.id'));
    Http::fake(['*/v1/analyze' => Http::response($payload)]);
    (new ProcessReportAnalysis($quiz->analysis_id))->handle(app(KbsClient::class), app(KbsRequestMapper::class));
    app(FinalizeQuizSession::class)->handle($quiz->fresh());

    $ready = $this->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertOk();
    $ready->assertJsonPath('data.status', 'FAILED')
        ->assertJsonPath('data.actual.total', 0)
        ->assertJsonPath('data.error.code', 'QUIZ_NO_ELIGIBLE_QUESTIONS');
});

test('correct answers and explanations are never exposed before submission', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 3);
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    foreach ($result['response']->json('data.questions') as $question) {
        expect($question)->not->toHaveKey('correct_option_id')
            ->and($question)->not->toHaveKey('explanation')
            ->and($question['answered'])->toBeNull();
    }
});

test('a valid answer is scored correctly and revealed only after submission', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->orderBy('sequence')->first();

    $response = $this->withToken(phase3bToken($user))
        ->postJson('/api/v1/quiz/'.$result['quizId'].'/answers', [
            'question_snapshot_id' => $snapshot->getKey(),
            'selected_option_id' => $snapshot->correct_option_id,
        ])
        ->assertOk();

    $response->assertJsonPath('data.answer.correct', true)
        ->assertJsonPath('data.answer.correct_option_id', $snapshot->correct_option_id)
        ->assertJsonPath('data.session.status', 'IN_PROGRESS');
});

test('an invalid option is rejected', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->first();

    $this->withToken(phase3bToken($user))
        ->postJson('/api/v1/quiz/'.$result['quizId'].'/answers', [
            'question_snapshot_id' => $snapshot->getKey(),
            'selected_option_id' => 'not-a-real-option',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'QUIZ_OPTION_INVALID');

    expect(StudentAnswer::query()->count())->toBe(0);
});

test('a snapshot belonging to another session is rejected', function () {
    // Both sessions are built via direct service calls (not authenticated HTTP) so
    // this isolates exactly one thing: cross-session snapshot ownership — the only
    // real HTTP call in this test authenticates as Student B.
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$userA, $reportA, $setA] = phase3bFixture();
    $quizA = phase3bReadyQuizViaService($userA, $reportA, $setA, 'CBC');

    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    [$userB, $reportB, $setB] = phase3bFixture();
    $quizB = phase3bReadyQuizViaService($userB, $reportB, $setB, 'CBC');

    $snapshotFromA = QuizQuestionSnapshot::query()->where('quiz_session_id', $quizA->getKey())->first();

    $this->withToken(phase3bToken($userB))
        ->postJson('/api/v1/quiz/'.$quizB->getKey().'/answers', [
            'question_snapshot_id' => $snapshotFromA->getKey(),
            'selected_option_id' => $snapshotFromA->correct_option_id,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'QUIZ_QUESTION_NOT_FOUND');
});

test('a duplicate answer submission is rejected and does not overwrite the first answer', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 14);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->first();
    $wrongOption = collect($snapshot->option_order_json)->first(fn ($id) => $id !== $snapshot->correct_option_id);

    $this->withToken(phase3bToken($user))->postJson('/api/v1/quiz/'.$result['quizId'].'/answers', [
        'question_snapshot_id' => $snapshot->getKey(),
        'selected_option_id' => $snapshot->correct_option_id,
    ])->assertOk();

    $this->withToken(phase3bToken($user))->postJson('/api/v1/quiz/'.$result['quizId'].'/answers', [
        'question_snapshot_id' => $snapshot->getKey(),
        'selected_option_id' => $wrongOption,
    ])->assertConflict()->assertJsonPath('error_code', 'QUIZ_ANSWER_ALREADY_SUBMITTED');

    expect(StudentAnswer::query()->where('quiz_question_snapshot_id', $snapshot->getKey())->count())->toBe(1)
        ->and(StudentAnswer::query()->where('quiz_question_snapshot_id', $snapshot->getKey())->first()->selected_option_id)
        ->toBe($snapshot->correct_option_id);
});

test('scoring and completion are based on actual total not a hard coded twenty', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 5);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');
    $result['response']->assertJsonPath('data.actual.total', 5);
    $snapshots = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->orderBy('sequence')->get();

    $lastResponse = null;
    foreach ($snapshots as $index => $snapshot) {
        $optionId = $index === 0
            ? collect($snapshot->option_order_json)->first(fn ($id) => $id !== $snapshot->correct_option_id)
            : $snapshot->correct_option_id;

        $lastResponse = $this->withToken(phase3bToken($user))->postJson('/api/v1/quiz/'.$result['quizId'].'/answers', [
            'question_snapshot_id' => $snapshot->getKey(),
            'selected_option_id' => $optionId,
        ])->assertOk();
    }

    $lastResponse->assertJsonPath('data.session.status', 'COMPLETED')
        ->assertJsonPath('data.session.score', 4)
        ->assertJsonPath('data.session.actual.total', 5);
    expect(QuizSession::query()->findOrFail($result['quizId'])->status)->toBe(QuizSessionStatus::Completed);
});

test('an in progress session can be resumed and shows partial progress', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 3);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->orderBy('sequence')->first();

    $this->withToken(phase3bToken($user))->postJson('/api/v1/quiz/'.$result['quizId'].'/answers', [
        'question_snapshot_id' => $snapshot->getKey(),
        'selected_option_id' => $snapshot->correct_option_id,
    ])->assertOk();

    $resume = $this->withToken(phase3bToken($user))->getJson('/api/v1/quiz/'.$result['quizId'])->assertOk();

    $resume->assertJsonPath('data.status', 'IN_PROGRESS')
        ->assertJsonPath('data.progress.answered_count', 1)
        ->assertJsonPath('data.questions.0.answered.correct', true)
        ->assertJsonPath('data.questions.1.answered', null);
});

test('editing the source question bank does not alter an already built quiz snapshot', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 1);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set, 'CBC');

    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $result['quizId'])->first();
    $originalText = $snapshot->question_text_json;
    $originalCorrect = $snapshot->correct_option_id;

    $question = Question::query()->findOrFail($snapshot->source_question_id);
    $question->update([
        'question_text_json' => ['en' => 'EDITED question text', 'ar' => 'نص معدل'],
        'correct_option_id' => $originalCorrect === 'a' ? 'b' : 'a',
        'content_version' => 2,
    ]);

    $snapshot->refresh();
    expect($snapshot->question_text_json)->toBe($originalText)
        ->and($snapshot->correct_option_id)->toBe($originalCorrect)
        ->and($snapshot->source_question_version)->toBe(1);
});

test('a quiz built from verification v1 stays independent from a later verification v2', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 5);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$user, $report, $set1] = phase3bFixture();
    $result = phase3bReadyQuiz($user, $report, $set1, 'CBC');

    $set2 = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 2, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 24, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'phase3b-v2-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => 'MATCH', 'category_gate_category' => ReportTestCategory::Cbc->value,
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);
    $set2->results()->create(['label' => 'HGB', 'value' => '13.0', 'unit' => 'g/dL', 'reference_range' => '12-16', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1]);

    $quiz = QuizSession::query()->findOrFail($result['quizId']);
    expect($quiz->verified_result_set_id)->toBe($set1->getKey())
        ->and($quiz->verified_result_set_version)->toBe(1);
});

test('another user cannot view or answer someone elses quiz session', function () {
    phase3bSeedQuestions(ReportTestCategory::Cbc, 1);
    app()->bind(CaseSpecificQuestionProvider::class, fn () => new FakeQuizCaseSpecificQuestionProvider(0));
    [$owner, $report, $set] = phase3bFixture();
    $quiz = phase3bReadyQuizViaService($owner, $report, $set, 'CBC');
    $snapshot = QuizQuestionSnapshot::query()->where('quiz_session_id', $quiz->getKey())->first();
    $other = User::factory()->student('2')->create();

    $this->withToken(phase3bToken($other))->getJson('/api/v1/quiz/'.$quiz->getKey())->assertForbidden();
    $this->withToken(phase3bToken($other))->postJson('/api/v1/quiz/'.$quiz->getKey().'/answers', [
        'question_snapshot_id' => $snapshot->getKey(),
        'selected_option_id' => $snapshot->correct_option_id,
    ])->assertForbidden();

    expect(StudentAnswer::query()->count())->toBe(0);
});
