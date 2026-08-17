<?php

// Reuses phase4eFixture()/phase4eToken()/phase4eGeminiHttpResponse()/
// phase4eValidGeminiContent() from tests/Feature/Phase4E/ResultExplanationApiTest.php
// (global Pest helpers), matching the exact convention Phase 4C's ComparisonAiTest.php
// already established for reusing ComparisonApiTest.php's own helpers.
//
// The real Gemini API is never called in this suite - every test either disables AI
// via config or intercepts the outbound HTTP call with Http::fake().

use App\Models\AiExplanation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['ai.gemini.enabled' => true, 'ai.gemini.api_key' => 'test-gemini-key', 'ai.result_explanation.enabled' => true]);
});

test('a valid Gemini JSON response is accepted, returned as AVAILABLE, and persisted to the cache', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse(phase4eValidGeminiContent()))]);

    $response = $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk();

    $response->assertJsonPath('data.status', 'AVAILABLE')
        ->assertJsonPath('data.content.possible_causes.0.context_code', 'ANEMIA_CAUSE_IRON_DEFICIENCY');
    expect(AiExplanation::query()->count())->toBe(1);
});

test('Arabic output is accepted when Arabic was requested', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse(phase4eValidGeminiContent(language: 'ar')))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'ar'])
        ->assertOk()
        ->assertJsonPath('data.status', 'AVAILABLE')
        ->assertJsonPath('data.content.language', 'ar');
});

test('a response claiming language=ar but written in English prose is rejected and falls back', function () {
    // This is the exact language-mixing bug the audit found: Gemini (or a
    // malfunctioning client) echoing "language": "ar" metadata while every
    // human-readable field stays in English. The metadata check alone
    // (response['language'] === 'ar') used to accept this; LanguagePurityChecker
    // must now reject it.
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent(language: 'ar');
    $content['overview'] = 'The results show a low hemoglobin reading.';
    $content['possible_causes'][0]['title'] = 'Iron deficiency';
    $content['possible_causes'][0]['explanation'] = 'The most common cause of reduced hemoglobin.';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'ar'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK')
        ->assertJsonPath('data.content.language', 'ar');
    expect(AiExplanation::query()->count())->toBe(0);
});

test('Arabic output containing whitelisted medical abbreviations, units, and rule codes is still accepted', function () {
    // Proves LanguagePurityChecker is not a naive "reject all Latin characters"
    // filter - HGB/MCV/R001/g/dL-style tokens inside genuine Arabic prose must pass.
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent(language: 'ar');
    $content['possible_causes'][0]['explanation'] =
        'الهيموغلوبين (HGB) بقيمة 9.5 g/dL أقل من المجال المرجعي، وفقًا للقاعدة R001.';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'ar'])
        ->assertOk()
        ->assertJsonPath('data.status', 'AVAILABLE');
});

test('invalid JSON text from Gemini falls back to the deterministic explanation', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'not valid json {{{']]], 'finishReason' => 'STOP']],
    ])]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
    expect(AiExplanation::query()->count())->toBe(0);
});

test('a Gemini response for the wrong category is rejected and falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse(phase4eValidGeminiContent(category: 'DIABETES')))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
    expect(AiExplanation::query()->count())->toBe(0);
});

test('a Gemini response for the wrong role is rejected and falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse(phase4eValidGeminiContent(role: 'student')))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('a Gemini response referencing a context_code Laravel never supplied as approved context is rejected and falls back', function () {
    // The core allow-list defense of the 2026-08-17 content redesign: Gemini cannot
    // invent a cause/symptom/next-step/red-flag code that was not actually present
    // in allowed_medical_context for this analysis.
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent();
    $content['possible_causes'][0]['context_code'] = 'FABRICATED_CAUSE_NEVER_SUPPLIED';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
    expect(AiExplanation::query()->count())->toBe(0);
});

test('a Gemini response referencing a symptom code that belongs to a different, unresolved catalog group is rejected', function () {
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent();
    // A genuinely real catalog code, but for THROMBOCYTOSIS_CONTEXT, which never
    // resolved for this fixture's single possible_anemia_pattern conclusion - proves
    // the allow-list is scoped per-analysis, not "any code that exists anywhere".
    $content['possible_symptoms'][] = ['context_code' => 'THROMBOCYTOSIS_SYMPTOM_USUALLY_NONE', 'text' => 'Often causes no symptoms.'];
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('a Gemini response with a treatment/dosage recommendation is rejected by the content safety guard and falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent();
    $content['next_steps'][0]['text'] = 'You should take 500 mg twice daily for best results.';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('a Gemini response claiming confirmed recovery is rejected by the content safety guard and falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent();
    $content['overview'] = 'This confirms the patient is fully recovered and no longer has anemia.';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('a missing required schema field is rejected and falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent();
    unset($content['limitations']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('an unexpected top level field is rejected by strict schema validation and falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent();
    $content['diagnosis'] = 'iron deficiency anemia';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('an oversized causes list is rejected and falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    $content = phase4eValidGeminiContent();
    $cause = $content['possible_causes'][0];
    $content['possible_causes'] = array_fill(0, 25, $cause); // duplicates also violate the "seen" uniqueness guard
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(phase4eGeminiHttpResponse($content))]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('a Gemini timeout falls back without breaking the result', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('a Gemini 429 falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('a Gemini 500 error falls back', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 500)]);

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
});

test('a missing API key falls back without ever calling Gemini', function () {
    config(['ai.gemini.api_key' => null]);
    [$user, , $analysis] = phase4eFixture();

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));
});

test('AI disabled via the shared gemini config gate falls back without ever calling Gemini', function () {
    config(['ai.gemini.enabled' => false]);
    [$user, , $analysis] = phase4eFixture();

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));
});

test('AI disabled via the result explanation specific gate falls back without ever calling Gemini, while comparison AI stays unaffected', function () {
    config(['ai.result_explanation.enabled' => false]);
    [$user, , $analysis] = phase4eFixture();

    $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk()
        ->assertJsonPath('data.status', 'FALLBACK');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));

    // The shared gemini.enabled gate is still true, so GeminiClient::isConfigured()
    // (used by Phase 4C comparison too) is unaffected by this Phase-4E-specific flag.
    expect(config('ai.gemini.enabled'))->toBeTrue();
});

test('the fallback explanation honors the requested language and never silently switches to English for Arabic requests', function () {
    config(['ai.gemini.api_key' => null]);
    [$user, , $analysis] = phase4eFixture();

    $response = $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'ar'])
        ->assertOk();

    $response->assertJsonPath('data.status', 'FALLBACK')
        ->assertJsonPath('data.content.language', 'ar');
    expect($response->json('data.content.overview'))->not->toBe('');
});

test('the result explanation remains fully successful even when every AI attempt fails', function () {
    [$user, , $analysis] = phase4eFixture();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 500)]);

    $response = $this->withToken(phase4eToken($user))
        ->postJson('/api/v1/analyses/'.$analysis->getKey().'/explanation', ['language' => 'en'])
        ->assertOk();

    // The fallback also draws from the approved catalog now (2026-08-17 redesign) -
    // it renders the same GENERAL_ANEMIA_CONTEXT cause, not a raw conclusion_code.
    expect($response->json('data.content.possible_causes.0.context_code'))->toBe('ANEMIA_CAUSE_IRON_DEFICIENCY');
});
