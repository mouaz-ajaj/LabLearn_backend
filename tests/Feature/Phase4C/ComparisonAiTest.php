<?php

use App\Enums\ReportTestCategory;
use App\Enums\UserRole;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Reuses phase4cToken()/phase4cVerifiedReport()/phase4cRunAnalysis()/phase4cNormalizedRow()
// from tests/Feature/Phase4C/ComparisonApiTest.php (global Pest helpers).
//
// The real Gemini API is never called in this suite - every test either disables AI
// via config or intercepts the outbound HTTP call with Http::fake(), matching the
// exact convention already established for KbsClient/OcrClient tests.

// rule_codes deliberately empty: KbsResponseValidator requires every conclusion
// rule_code to also appear in rule_traces[], which phase4cRunAnalysis() always sends
// empty - matching this fixture's rule_codes to that constraint rather than fighting it.
function phase4cAnemiaConclusion(): array
{
    return [
        'code' => 'possible_anemia_pattern', 'level' => 'educational_finding',
        'title' => ['en' => 'Possible anemia pattern', 'ar' => 'نمط محتمل لفقر الدم'],
        'summary' => ['en' => 'Low hemoglobin needs clinical context.', 'ar' => 'يحتاج انخفاض الهيموغلوبين إلى سياق سريري.'],
        'evidence' => [], 'rule_codes' => [],
    ];
}

/** @param  array<string, mixed>  $overrides */
function phase4cGeminiContent(string $role = 'regular', array $overrides = []): array
{
    $base = [
        'schema_version' => '2',
        'language' => 'en',
        'role' => $role,
        'category' => 'CBC',
        'overall_picture' => 'Hemoglobin moved toward the reference range but remains below it, and the anemia pattern persists.',
        'normalized_findings' => [],
        'better_but_still_abnormal' => [
            ['analyte_id' => 'hemoglobin', 'text' => 'Hemoglobin increased but remains below the reference range.'],
        ],
        'new_or_worse_findings' => [],
        'pattern_changes' => [
            ['conclusion_code' => 'possible_anemia_pattern', 'transition' => 'PERSISTED', 'text' => 'The anemia pattern remains supported by the latest analysis.'],
        ],
        'interpretation' => 'The laboratory pattern may still be consistent with anemia despite the improvement in hemoglobin.',
        'unchanged_summary' => 'No other comparable markers were included in this comparison.',
        'limitations' => 'This comparison is educational only, is not a medical diagnosis or treatment plan, and does not confirm clinical improvement or deterioration.',
    ];

    if ($role === 'student') {
        $base['student_context'] = [
            'clinical_significance' => 'Persistence of the anemia pattern alongside a rising hemoglobin suggests partial, not complete, laboratory normalization.',
            'differential_context' => [
                ['context_code' => 'ANEMIA_DDX_MECHANISM_UNDERPRODUCTION', 'text' => 'Reduced production (nutritional deficiency, chronic disease, marrow disorder)'],
            ],
            'interpretation_clues' => [
                ['context_code' => 'ANEMIA_DISTINGUISH_MCV', 'text' => 'MCV helps classify anemia as microcytic, normocytic, or macrocytic.'],
            ],
            'persistent_abnormalities' => [],
        ];
    }

    return array_merge($base, $overrides);
}

/** @param  array<string, mixed>  $content */
function phase4cGeminiHttpPayload(array $content): array
{
    return [
        'candidates' => [[
            'content' => ['parts' => [['text' => json_encode($content, JSON_UNESCAPED_UNICODE)]]],
            'finishReason' => 'STOP',
        ]],
    ];
}

/** @return array{0: User, 1: Report, 2: Report} two CBC reports: HGB LOW -> less LOW (still low), anemia pattern PERSISTED */
function phase4cAiFixture(UserRole $role = UserRole::Regular): array
{
    $user = User::factory()->create(['role' => $role]);
    [$reportA, $setA] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '8.5', 'unit' => 'g/dL', 'reference_range' => '12-16']], now()->subDays(20));
    [$reportB, $setB] = phase4cVerifiedReport($user, ReportTestCategory::Cbc, [['label' => 'HGB', 'value' => '9.5', 'unit' => 'g/dL', 'reference_range' => '12-16']], now()->subDays(1));
    phase4cRunAnalysis($reportA, $setA, $user, [phase4cNormalizedRow($setA->results->first()->id, 'hemoglobin', 'Hemoglobin', 8.5, 'g/dL', 12, 16, 'low')], [phase4cAnemiaConclusion()]);
    phase4cRunAnalysis($reportB, $setB, $user, [phase4cNormalizedRow($setB->results->first()->id, 'hemoglobin', 'Hemoglobin', 9.5, 'g/dL', 12, 16, 'low')], [phase4cAnemiaConclusion()]);

    return [$user, $reportA, $reportB];
}

beforeEach(function () {
    Queue::fake();
    $GLOBALS['phase4cKbsFakeRegistered'] = false;
    $GLOBALS['phase4cKbsPayloads'] = [];
    config(['ai.gemini.enabled' => true, 'ai.gemini.api_key' => 'test-gemini-key']);
});

test('a valid Regular Gemini JSON response is accepted and returned with status AVAILABLE', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture(UserRole::Regular);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular')))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('AVAILABLE')
        ->and($response->json('data.ai_context.content.role'))->toBe('regular')
        ->and($response->json('data.ai_context.content.better_but_still_abnormal.0.analyte_id'))->toBe('hemoglobin')
        ->and($response->json('data.ai_context.content.pattern_changes.0.conclusion_code'))->toBe('possible_anemia_pattern')
        ->and($response->json('data.ai_context.content.pattern_changes.0.transition'))->toBe('PERSISTED')
        ->and($response->json('data.ai_context.content'))->not->toHaveKey('student_context');
});

test('a valid Student Gemini JSON response is accepted, includes student_context, and materially differs from Regular', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture(UserRole::Student);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('student')))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('AVAILABLE')
        ->and($response->json('data.ai_context.content.role'))->toBe('student')
        ->and($response->json('data.ai_context.content.student_context.differential_context.0.context_code'))->toBe('ANEMIA_DDX_MECHANISM_UNDERPRODUCTION')
        ->and($response->json('data.ai_context.content.student_context.interpretation_clues.0.context_code'))->toBe('ANEMIA_DISTINGUISH_MCV');
});

test('the role in the response is always derived from the account, never from client input', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture(UserRole::Regular);
    // Gemini is stubbed to (incorrectly) claim the student role - this must be rejected
    // because GeminiContextualizer requests the schema pinned to the ACCOUNT's real role.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('student')))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK')
        ->and($response->json('data.ai_context.content.role'))->toBe('regular');
});

test('Arabic output is accepted when Arabic was requested', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture(UserRole::Regular);
    $arabicContent = phase4cGeminiContent('regular', [
        'language' => 'ar',
        'overall_picture' => 'ارتفع الهيموغلوبين واقترب من المجال المرجعي، لكنه ما يزال منخفضًا، وما زال نمط فقر الدم مدعومًا.',
        'better_but_still_abnormal' => [['analyte_id' => 'hemoglobin', 'text' => 'ارتفع الهيموغلوبين لكنه ما يزال أقل من المجال المرجعي.']],
        'pattern_changes' => [['conclusion_code' => 'possible_anemia_pattern', 'transition' => 'PERSISTED', 'text' => 'ما زال نمط فقر الدم مدعومًا في التقرير الأحدث.']],
        'interpretation' => 'قد يظل النمط المخبري متوافقًا مع فقر الدم رغم تحسّن الهيموغلوبين.',
        'unchanged_summary' => 'لم تُدرَج مؤشرات أخرى قابلة للمقارنة في هذه المقارنة.',
        'limitations' => 'هذه المقارنة تعليمية فقط ولا تمثل تشخيصًا طبيًا أو خطة علاج، ولا تؤكد تحسنًا أو تدهورًا سريريًا.',
    ]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload($arabicContent))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'ar',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('AVAILABLE')
        ->and($response->json('data.ai_context.language'))->toBe('ar')
        ->and($response->json('data.ai_context.content.language'))->toBe('ar');
});

test('a response claiming language=ar but written in English prose is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture(UserRole::Regular);
    $mislabeled = phase4cGeminiContent('regular', ['language' => 'ar']); // English prose, ar metadata only
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload($mislabeled))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'ar',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK')
        ->and($response->json('data.ai_context.content.language'))->toBe('ar');
});

test('invalid JSON text from Gemini falls back to the deterministic explanation', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'not valid json at all {{{']]]]],
    ])]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK')
        ->and($response->json('data.ai_context.content.schema_version'))->toBe('2')
        ->and($response->json('data.ai_context.content.language'))->toBe('en');
});

test('a Gemini response in the wrong language is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular', ['language' => 'ar'])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a Gemini response for the wrong category is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular', ['category' => 'DIABETES'])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a Gemini response referencing an analyte Laravel never supplied is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular', [
        'better_but_still_abnormal' => [['analyte_id' => 'invented_analyte_xyz', 'text' => 'This analyte does not exist in the comparison.']],
    ])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('placing an analyte in the wrong section (Laravel decided it is better-but-still-abnormal, Gemini claims normalized) is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular', [
        // hemoglobin is BELOW_REFERENCE at both points (MOVED_CLOSER_BUT_STILL_ABNORMAL) -
        // it was never supplied inside the normalized_findings allow-list.
        'normalized_findings' => [['analyte_id' => 'hemoglobin', 'text' => 'Hemoglobin returned to the reference range.']],
        'better_but_still_abnormal' => [],
    ])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a pattern_changes entry referencing an unsupported conclusion_code is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular', [
        'pattern_changes' => [['conclusion_code' => 'invented_pattern_xyz', 'transition' => 'APPEARED', 'text' => 'This pattern was never computed.']],
    ])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a pattern_changes entry with a transition value that does not match what Laravel computed is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular', [
        // Laravel computed PERSISTED for this fixture (present in both reports) - Gemini
        // cannot claim it APPEARED or DISAPPEARED instead.
        'pattern_changes' => [['conclusion_code' => 'possible_anemia_pattern', 'transition' => 'APPEARED', 'text' => 'A new anemia pattern appeared.']],
    ])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a Student response referencing a differential context_code that was never resolved for this comparison is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture(UserRole::Student);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('student', [
        'student_context' => array_merge(phase4cGeminiContent('student')['student_context'], [
            // Real catalog code, but belongs to a DIFFERENT, unresolved category (liver, not anemia).
            'differential_context' => [['context_code' => 'HEPATOCELLULAR_DDX_FATTY_LIVER', 'text' => 'Fatty liver.']],
        ]),
    ])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a Gemini response claiming clinical improvement/recovery is rejected by the content safety guard and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular', [
        'interpretation' => 'The anemia is cured and the patient is fully recovered.',
    ])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a Gemini response with a treatment/dosage recommendation is rejected by the content safety guard and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload(phase4cGeminiContent('regular', [
        'interpretation' => 'Take 500 mg iron tablets twice daily.',
    ])))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a missing required schema field is rejected and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    $incomplete = phase4cGeminiContent('regular');
    unset($incomplete['limitations']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload($incomplete))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('an unexpected top level field is rejected by strict schema validation and falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    $extra = phase4cGeminiContent('regular');
    $extra['diagnosis'] = 'iron deficiency anemia, confirmed';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4cGeminiHttpPayload($extra))]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a Gemini timeout falls back without breaking the comparison', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection('cURL error 28: Operation timed out')]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK')
        ->and($response->json('data.comparison.category'))->toBe('CBC');
});

test('a Gemini 500 error falls back', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 500, 'status' => 'INTERNAL']], 500)]);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
});

test('a missing API key falls back without ever calling Gemini', function () {
    config(['ai.gemini.api_key' => null]);
    [$user, $reportA, $reportB] = phase4cAiFixture();

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));
});

test('AI disabled via configuration falls back without ever calling Gemini', function () {
    config(['ai.gemini.enabled' => false]);
    [$user, $reportA, $reportB] = phase4cAiFixture();

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.status'))->toBe('FALLBACK');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));
});

test('the fallback explanation is pre-grouped, useful, and honors the requested language and role for Regular', function () {
    Http::preventStrayRequests();
    config(['ai.gemini.enabled' => false]);
    [$user, $reportA, $reportB] = phase4cAiFixture(UserRole::Regular);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'ar',
    ])->assertOk();

    expect($response->json('data.ai_context.language'))->toBe('ar')
        ->and($response->json('data.ai_context.content.language'))->toBe('ar')
        ->and($response->json('data.ai_context.content.role'))->toBe('regular')
        ->and($response->json('data.ai_context.content.better_but_still_abnormal.0.analyte_id'))->toBe('hemoglobin')
        ->and($response->json('data.ai_context.content.pattern_changes.0.transition'))->toBe('PERSISTED')
        ->and($response->json('data.ai_context.content'))->not->toHaveKey('student_context');
});

test('the fallback explanation includes deterministic student_context for Student role', function () {
    Http::preventStrayRequests();
    config(['ai.gemini.enabled' => false]);
    [$user, $reportA, $reportB] = phase4cAiFixture(UserRole::Student);

    $response = $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk();

    expect($response->json('data.ai_context.content.role'))->toBe('student')
        ->and($response->json('data.ai_context.content.student_context.clinical_significance'))->not->toBe('')
        ->and($response->json('data.ai_context.content.student_context.differential_context'))->not->toBeEmpty();
});

test('comparison remains fully successful even when every AI attempt fails', function () {
    [$user, $reportA, $reportB] = phase4cAiFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('', 503)]);

    $this->withToken(phase4cToken($user))->postJson('/api/v1/comparisons', [
        'report_ids' => [$reportA->getKey(), $reportB->getKey()], 'language' => 'en',
    ])->assertOk()
        ->assertJsonPath('data.comparison.category', 'CBC')
        ->assertJsonPath('data.ai_context.status', 'FALLBACK');
});
