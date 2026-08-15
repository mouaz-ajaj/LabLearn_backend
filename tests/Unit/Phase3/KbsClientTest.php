<?php

use App\Enums\AnalysisFlow;
use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function kbsClientAnalysis(): Analysis
{
    return new Analysis([
        'report_id' => 1,
        'verified_result_set_id' => 42,
        'verified_result_set_version' => 3,
        'user_id' => 1,
        'report_category' => 'CBC',
        'status' => AnalysisStatus::Processing,
        'flow' => AnalysisFlow::DirectResult,
        'identity_key' => str_repeat('a', 64),
        'ruleset_version' => 'rules-1',
    ]);
}

function kbsClientPayload(): array
{
    return [
        'success' => true, 'schema_version' => '1', 'input_schema_version' => '1', 'output_schema_version' => '1',
        'engine_version' => '1.0.0', 'ruleset_version' => 'rules-1', 'analyte_catalog_version' => 'catalog-1',
        'knowledge_base_version' => 'kb-1', 'request_id' => 'request-123', 'category' => 'CBC',
        'verified_result_set' => ['id' => 42, 'version' => 3], 'status' => 'completed',
        'category_validation' => ['status' => 'MATCH'], 'normalized_results' => [], 'facts' => [],
        'conclusions' => [['code' => 'finding', 'level' => 'educational', 'title' => ['en' => 'Finding'], 'summary' => ['en' => 'Summary'], 'evidence' => [], 'rule_codes' => ['rule-1']]],
        'rule_traces' => [['rule_code' => 'rule-1', 'rule_version' => 1, 'fired' => true, 'conditions' => [], 'evidence' => [], 'conclusion_codes' => ['finding']]],
        'missing_information' => [], 'warnings' => [], 'summary' => ['en' => 'Summary'], 'disclaimer' => ['en' => 'Educational only'],
    ];
}

test('client authenticates internal metadata requests and validates versions', function () {
    config(['kbs.api_key' => 'internal-secret']);
    Http::fake(['*/v1/metadata' => Http::response([
        'input_schema_version' => '1', 'output_schema_version' => '1', 'engine_version' => '1.0.0',
        'ruleset_version' => 'rules-1', 'analyte_catalog_version' => 'catalog-1',
    ])]);

    expect(app(KbsClient::class)->metadata()['ruleset_version'])->toBe('rules-1');
    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Internal-KBS-Key', 'internal-secret'));
});

test('validator rejects conclusions whose evidence chain references an absent rule trace', function () {
    $payload = kbsClientPayload();
    $payload['conclusions'][0]['rule_codes'] = ['missing-rule'];
    Http::fake(['*/v1/analyze' => Http::response($payload)]);

    expect(fn () => app(KbsClient::class)->analyze(['input' => true], kbsClientAnalysis()))
        ->toThrow(KbsException::class, 'invalid response');
});

test('health is intentionally unauthenticated while still enforcing service identity', function () {
    config(['kbs.api_key' => 'internal-secret']);
    Http::fake(['*/health' => Http::response([
        'status' => 'ok', 'service' => 'lablearn-kbs', 'engine_version' => '1.0.0',
        'ruleset_version' => 'rules-1', 'supported_categories' => ['CBC'],
    ])]);

    expect(app(KbsClient::class)->health()['service'])->toBe('lablearn-kbs');
    Http::assertSent(fn ($request): bool => ! $request->hasHeader('X-Internal-KBS-Key'));
});
