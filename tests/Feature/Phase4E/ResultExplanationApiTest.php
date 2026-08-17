<?php

use App\Enums\AiTaskType;
use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Enums\PatientSex;
use App\Enums\ReportSourceType;
use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Models\AiExplanation;
use App\Models\Analysis;
use App\Models\AnalysisConclusion;
use App\Models\Report;
use App\Models\RuleTrace;
use App\Models\User;
use App\Models\VerifiedResultSet;
use App\Services\Ai\AiExplanationCache;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Phase 4E fixtures - self-contained (not reused from Phase 3/4C/4D's own
| helpers), matching the established per-phase isolation convention. Builds a
| SUCCEEDED Analysis directly via factories (no HTTP/KBS round trip needed -
| this phase only cares about what happens once an Analysis already exists).
|--------------------------------------------------------------------------
*/

function phase4eFixture(?User $user = null, ReportTestCategory $category = ReportTestCategory::Cbc, AnalysisFlow $flow = AnalysisFlow::DirectResult): array
{
    $user ??= User::factory()->regular()->create();
    $report = Report::factory()->for($user)->create([
        'test_category' => $category,
        'source_type' => ReportSourceType::Image,
        'status' => ReportStatus::Completed,
        'patient_age_years' => 30,
        'patient_sex' => PatientSex::Female,
    ]);
    $set = VerifiedResultSet::query()->create([
        'report_id' => $report->getKey(), 'version' => 1, 'confirmed_by_user_id' => $user->getKey(),
        'patient_age_years' => 30, 'patient_sex' => PatientSex::Female,
        'idempotency_key' => 'phase4e-fixture-'.fake()->uuid(), 'excluded_source_result_ids' => [],
        'category_gate_status' => 'MATCH', 'category_gate_category' => $category->value,
        'category_gate_evidence' => ['reason' => 'test'], 'confirmed_at' => now(),
    ]);
    $set->results()->create(['label' => 'HGB', 'value' => '9.5', 'unit' => 'g/dL', 'reference_range' => '12-16', 'was_added_manually' => true, 'was_modified' => false, 'display_order' => 1]);

    $analysis = Analysis::factory()->create([
        'report_id' => $report->getKey(),
        'verified_result_set_id' => $set->getKey(),
        'verified_result_set_version' => 1,
        'user_id' => $user->getKey(),
        'report_category' => $category->value,
        'status' => AnalysisStatus::Succeeded,
        'flow' => $flow,
        'identity_key' => hash('sha256', 'phase4e-'.fake()->uuid()),
        'ruleset_version' => 'test-ruleset',
        'started_at' => now(),
        'completed_at' => now(),
        'duration_ms' => 500,
        'attempt_count' => 1,
        'summary_json' => ['en' => 'One educational finding was detected.', 'ar' => 'تم رصد ملاحظة تعليمية واحدة.'],
        'disclaimer_json' => ['en' => 'Educational only.', 'ar' => 'لأغراض تعليمية فقط.'],
        'normalized_results_json' => [[
            'source_id' => 1, 'analyte_id' => 'hemoglobin', 'display_name' => 'Hemoglobin',
            'value' => 9.5, 'unit' => 'g/dL', 'original_value' => 9.5, 'original_unit' => 'g/dL',
            'reference_range' => ['low' => 12, 'high' => 16], 'status' => 'low',
        ]],
        'missing_information_json' => [[
            'code' => 'MISSING_REQUIRED_ANALYTE', 'analyte_id' => 'platelets',
            'message' => ['en' => 'Platelets are required.', 'ar' => 'الصفائح مطلوبة.'],
        ]],
    ]);

    AnalysisConclusion::factory()->for($analysis)->create([
        'conclusion_code' => 'possible_anemia_pattern',
        'level' => 'educational_finding',
        'title_json' => ['en' => 'Possible anemia pattern', 'ar' => 'نمط محتمل لفقر الدم'],
        'summary_json' => ['en' => 'Low hemoglobin needs clinical context.', 'ar' => 'يحتاج انخفاض الهيموغلوبين إلى سياق سريري.'],
        'evidence_json' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']],
        'rule_codes_json' => ['R001'],
        'display_order' => 1,
    ]);

    RuleTrace::query()->create([
        'analysis_id' => $analysis->getKey(),
        'rule_code' => 'R001',
        'rule_version' => 1,
        'fired' => true,
        'conditions_json' => [],
        'evidence_json' => [['source_id' => 1, 'analyte_id' => 'hemoglobin', 'label' => 'Hemoglobin', 'value' => 9.5, 'unit' => 'g/dL', 'status' => 'low']],
        'conclusion_codes_json' => ['possible_anemia_pattern'],
    ]);

    return [$user, $report, $analysis->fresh(['conclusions', 'ruleTraces'])];
}

function phase4eToken(User $user): string
{
    return $user->createToken('phase4e-test')->plainTextToken;
}

// The real local dev GEMINI_API_KEY is present in this environment's config even
// under `php artisan test` (APP_ENV=testing does not blank it out) - every test in
// this file must start from a definitely-disabled Gemini (no real network call ever
// happens by accident) and explicitly re-enable it with a fake key + Http::fake()
// only when it specifically wants to exercise the AI/cache path, mirroring the
// established Phase 4C ComparisonAiTest.php convention.
beforeEach(function () {
    config(['ai.gemini.api_key' => null]);
});

/** Real (structurally valid) Gemini generateContent HTTP response for a result-explanation request. */
function phase4eGeminiHttpResponse(array $content): array
{
    return [
        'candidates' => [[
            'content' => ['parts' => [['text' => json_encode($content, JSON_UNESCAPED_UNICODE)]]],
            'finishReason' => 'STOP',
        ]],
    ];
}

/**
 * v2 schema content (2026-08-17 content redesign). Uses REAL approved context codes
 * from resources/medical_context/cbc.json's GENERAL_ANEMIA_CONTEXT group (the group
 * that resolves for phase4eFixture()'s "possible_anemia_pattern" conclusion), since
 * the response validator allow-lists every context_code against what was actually
 * resolved for the analysis - a fabricated code would always be rejected.
 */
function phase4eValidGeminiContent(string $language = 'en', string $category = 'CBC', string $role = 'regular'): array
{
    $content = $language === 'ar' ? [
        'schema_version' => '2', 'language' => $language, 'category' => $category, 'role' => $role,
        'overview' => 'تُظهر النتائج انخفاضًا في قيمة الهيموغلوبين، وهو نمط يُعرف بفقر الدم.',
        'possible_causes' => [[
            'context_code' => 'ANEMIA_CAUSE_IRON_DEFICIENCY',
            'title' => 'نقص الحديد',
            'explanation' => 'السبب الأكثر شيوعًا لانخفاض الهيموغلوبين.',
        ]],
        'possible_symptoms' => [[
            'context_code' => 'ANEMIA_SYMPTOM_FATIGUE',
            'text' => 'قد يشعر بعض الأشخاص بالتعب والإرهاق.',
        ]],
        'clinical_relevance' => 'يعكس فقر الدم انخفاض قدرة الدم على نقل الأكسجين.',
        'next_steps' => [[
            'context_code' => 'ANEMIA_NEXT_STEP_DISCUSS',
            'text' => 'يُنصح بمناقشة هذه النتيجة مع مختص طبي.',
        ]],
        'red_flags' => [[
            'context_code' => 'ANEMIA_RED_FLAG_SEVERE_SYMPTOMS',
            'text' => 'الأعراض الشديدة مثل ألم الصدر قد تستدعي تقييمًا أسرع.',
        ]],
        'limitations' => 'هذا الشرح تعليمي فقط ولا يمثل تشخيصًا طبيًا أو خطة علاج أو تأكيدًا لتحسّن أو تدهور الحالة السريرية.',
    ] : [
        'schema_version' => '2', 'language' => $language, 'category' => $category, 'role' => $role,
        'overview' => 'The results show a low hemoglobin reading, a pattern known as anemia.',
        'possible_causes' => [[
            'context_code' => 'ANEMIA_CAUSE_IRON_DEFICIENCY',
            'title' => 'Iron deficiency',
            'explanation' => 'The most common cause of reduced hemoglobin.',
        ]],
        'possible_symptoms' => [[
            'context_code' => 'ANEMIA_SYMPTOM_FATIGUE',
            'text' => 'Some people may feel fatigue and weakness.',
        ]],
        'clinical_relevance' => 'Anemia reflects a reduced ability of the blood to carry oxygen.',
        'next_steps' => [[
            'context_code' => 'ANEMIA_NEXT_STEP_DISCUSS',
            'text' => 'Discussing this result with a healthcare professional is recommended.',
        ]],
        'red_flags' => [[
            'context_code' => 'ANEMIA_RED_FLAG_SEVERE_SYMPTOMS',
            'text' => 'Severe symptoms such as chest pain may warrant faster evaluation.',
        ]],
        'limitations' => 'This explanation is educational only and does not represent a medical diagnosis, a treatment plan, or a confirmation of clinical improvement or deterioration.',
    ];

    if ($role === 'student') {
        $content['student_context'] = $language === 'ar' ? [
            'pathophysiology' => 'ينتج فقر الدم عن فقدان دم أو انخفاض إنتاج الكريات الحمراء أو زيادة تكسّرها.',
            'differential_considerations' => [[
                'context_code' => 'ANEMIA_DDX_MECHANISM_UNDERPRODUCTION',
                'text' => 'انخفاض الإنتاج بسبب نقص غذائي أو مرض مزمن.',
            ]],
            'distinguishing_information' => [[
                'context_code' => 'ANEMIA_DISTINGUISH_MCV',
                'text' => 'يساعد MCV في تصنيف فقر الدم.',
            ]],
            'learning_takeaway' => 'تصنيف فقر الدم بحسب MCV هو الخطوة الأولى المعتادة.',
        ] : [
            'pathophysiology' => 'Anemia arises from blood loss, reduced production, or increased destruction of red cells.',
            'differential_considerations' => [[
                'context_code' => 'ANEMIA_DDX_MECHANISM_UNDERPRODUCTION',
                'text' => 'Reduced production from nutritional deficiency or chronic disease.',
            ]],
            'distinguishing_information' => [[
                'context_code' => 'ANEMIA_DISTINGUISH_MCV',
                'text' => 'MCV helps classify the anemia.',
            ]],
            'learning_takeaway' => 'Classifying anemia by MCV is the standard first step.',
        ];
    }

    return $content;
}

test('unauthenticated request is rejected', function () {
    [, , $analysis] = phase4eFixture();

    $this->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertUnauthorized();
});

test('a user cannot request an explanation for another users analysis', function () {
    [, , $analysis] = phase4eFixture();
    $other = User::factory()->regular()->create();

    $this->withToken(phase4eToken($other))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertForbidden();

    expect(AiExplanation::query()->count())->toBe(0);
});

test('a locked quiz first analysis cannot have its explanation requested either', function () {
    [$user, $report, $analysis] = phase4eFixture(flow: AnalysisFlow::QuizFirst);
    // No completed QuizSession references this analysis, so it stays locked -
    // the explanation endpoint must respect the same lock ShowAnalysisController does.

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'QUIZ_RESULT_LOCKED');

    expect(AiExplanation::query()->count())->toBe(0);
});

test('an analysis that has not succeeded yet has no explanation available', function () {
    [$user, , $analysis] = phase4eFixture();
    $analysis->update(['status' => AnalysisStatus::Queued]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertConflict()
        ->assertJsonPath('error_code', 'EXPLANATION_NOT_AVAILABLE');
});

test('language must be ar or en', function () {
    [$user, , $analysis] = phase4eFixture();

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'fr'])
        ->assertUnprocessable();
});

test('the client cannot impersonate a role - it is always derived from the authenticated user', function () {
    Http::fake(); // Gemini disabled by default in testing env -> deterministic fallback, still role-correct.
    [$user, , $analysis] = phase4eFixture(User::factory()->regular()->create());

    $response = $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en', 'role' => 'student'])
        ->assertOk();

    $response->assertJsonPath('data.role', 'regular');
});

test('a regular user gets a regular profile explanation', function () {
    $regular = User::factory()->regular()->create();
    [, , $regularAnalysis] = phase4eFixture($regular);

    $response = $this->withToken(phase4eToken($regular))
        ->postJson('/api/v1/analyses/'.$regularAnalysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk();
    $response->assertJsonPath('data.role', 'regular')
        ->assertJsonPath('data.content.role', 'regular');
});

test('a student gets a student profile explanation', function () {
    $student = User::factory()->student('4')->create();
    [, , $studentAnalysis] = phase4eFixture($student);

    $response = $this->withToken(phase4eToken($student))
        ->postJson('/api/v1/analyses/'.$studentAnalysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk();
    $response->assertJsonPath('data.role', 'student')
        ->assertJsonPath('data.content.role', 'student');
});

test('the response contract exposes status language role and content, and honors arabic', function () {
    [$user, , $analysis] = phase4eFixture();

    $response = $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'ar'])
        ->assertOk();

    $response->assertJsonPath('data.analysis_id', $analysis->getKey())
        ->assertJsonPath('data.language', 'ar')
        ->assertJsonPath('data.content.language', 'ar')
        ->assertJsonStructure(['data' => ['analysis_id', 'status', 'language', 'role', 'content' => [
            'schema_version', 'language', 'category', 'role', 'overview', 'possible_causes',
            'possible_symptoms', 'clinical_relevance', 'next_steps', 'red_flags', 'limitations',
        ]]]);
});

test('a cache miss creates exactly one ai_explanations row and a cache hit reuses it without a second gemini call', function () {
    config(['ai.result_explanation.enabled' => true, 'ai.gemini.enabled' => true]);
    config(['ai.gemini.api_key' => 'test-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse(phase4eValidGeminiContent()))]);
    [$user, , $analysis] = phase4eFixture();

    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk()
        ->assertJsonPath('data.status', 'AVAILABLE');
    expect(AiExplanation::query()->count())->toBe(1);

    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk()
        ->assertJsonPath('data.status', 'AVAILABLE');

    expect(AiExplanation::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('a different language produces a separate cache row and both remain independently retrievable', function () {
    config(['ai.result_explanation.enabled' => true, 'ai.gemini.enabled' => true, 'ai.gemini.api_key' => 'test-key']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => function ($request) {
            $body = json_decode($request->body(), true);
            $userText = $body['contents'][0]['parts'][0]['text'];
            $language = str_contains($userText, '"language":"ar"') ? 'ar' : 'en';

            return Http::response(phase4eGeminiHttpResponse(phase4eValidGeminiContent($language)));
        },
    ]);
    [$user, , $analysis] = phase4eFixture();

    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk();
    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'ar'])->assertOk();

    expect(AiExplanation::query()->count())->toBe(2)
        ->and(AiExplanation::query()->where('language', 'en')->count())->toBe(1)
        ->and(AiExplanation::query()->where('language', 'ar')->count())->toBe(1);
    Http::assertSentCount(2);

    // Returning to English reuses the original cached explanation - no third call.
    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk();
    Http::assertSentCount(2);
});

test('a different role produces a separate cache row', function () {
    config(['ai.result_explanation.enabled' => true, 'ai.gemini.enabled' => true, 'ai.gemini.api_key' => 'test-key']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => function ($request) {
            $body = json_decode($request->body(), true);
            $userText = $body['contents'][0]['parts'][0]['text'];
            $role = str_contains($userText, '"user_role":"student"') ? 'student' : 'regular';

            return Http::response(phase4eGeminiHttpResponse(phase4eValidGeminiContent(role: $role)));
        },
    ]);
    $student = User::factory()->student('4')->create();
    [, , $analysis] = phase4eFixture($student);

    $this->withToken(phase4eToken($student))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk()
        ->assertJsonPath('data.role', 'student');

    expect(AiExplanation::query()->where('role', 'student')->count())->toBe(1)
        ->and(AiExplanation::query()->where('role', 'regular')->count())->toBe(0);
});

test('a prompt version change creates an independent cache entry rather than reusing the old one', function () {
    config(['ai.result_explanation.enabled' => true, 'ai.gemini.enabled' => true, 'ai.gemini.api_key' => 'test-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse(phase4eValidGeminiContent()))]);
    [$user, , $analysis] = phase4eFixture();

    config(['ai.result_explanation.prompt_version' => 'v1']);
    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk();

    config(['ai.result_explanation.prompt_version' => 'v2']);
    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk();

    expect(AiExplanation::query()->count())->toBe(2)
        ->and(AiExplanation::query()->pluck('prompt_version')->sort()->values()->all())->toBe(['v1', 'v2']);
    Http::assertSentCount(2);
});

test('requesting an explanation never mutates the deterministic analysis or its conclusions/rule traces', function () {
    [$user, , $analysis] = phase4eFixture();
    $originalSummary = $analysis->summary_json;
    $originalConclusionCount = $analysis->conclusions->count();
    $originalTraceCount = $analysis->ruleTraces->count();

    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk();

    $fresh = $analysis->fresh(['conclusions', 'ruleTraces']);
    expect($fresh->summary_json)->toBe($originalSummary)
        ->and($fresh->conclusions)->toHaveCount($originalConclusionCount)
        ->and($fresh->ruleTraces)->toHaveCount($originalTraceCount)
        ->and($fresh->status)->toBe(AnalysisStatus::Succeeded);
});

test('a duplicate cache write for the same identity is resolved safely without creating two rows or throwing', function () {
    [, , $analysis] = phase4eFixture();
    $cache = app(AiExplanationCache::class);
    $identityArgs = [$analysis, AiTaskType::ResultExplanation, 'en', 'regular', 'v1', '2'];

    $first = $cache->store(...$identityArgs, model: 'gemini-test', content: phase4eValidGeminiContent());
    // Simulates the "loser" of a concurrent race: the same identity is written again
    // (e.g. two requests that both saw a cache miss) - this must not throw and must
    // not create a second row, matching the unique index as the real safety net.
    $second = $cache->store(...$identityArgs, model: 'gemini-test', content: phase4eValidGeminiContent());

    expect($second->getKey())->toBe($first->getKey())
        ->and(AiExplanation::query()->count())->toBe(1);
});

test('requesting an explanation never reruns ocr or kbs', function () {
    [$user, , $analysis] = phase4eFixture();

    // Any stray call to an OCR/KBS-shaped URL fails the test loudly; only the
    // Gemini host (if reached at all - AI is off by default in testing env) is
    // ever allowed to be a real network target.
    Http::fake([
        '*/v1/analyze' => Http::response(['ok' => false], 500),
        '*/v1/validate' => Http::response(['ok' => false], 500),
        '*ocr*' => Http::response(['ok' => false], 500),
    ]);

    $this->withToken(phase4eToken($user))->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/analyze') || str_contains($request->url(), '/v1/validate'));
});
