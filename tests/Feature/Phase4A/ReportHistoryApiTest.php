<?php

use App\Enums\ReportStatus;
use App\Enums\ReportTestCategory;
use App\Jobs\ProcessReportAnalysis;
use App\Jobs\ProcessReportOcr;
use App\Models\Analysis;
use App\Models\QuizSession;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function phase4aToken(User $user): string
{
    return $user->createToken('phase-4a-test')->plainTextToken;
}

/** @return array<int, Report> */
function phase4aSeedReports(User $user, array $specs): array
{
    return array_map(
        fn (array $spec) => Report::factory()->for($user)->create([
            'test_category' => $spec['category'] ?? ReportTestCategory::Cbc,
            'status' => $spec['status'] ?? ReportStatus::Uploaded,
            'created_at' => $spec['created_at'] ?? now(),
            'updated_at' => $spec['created_at'] ?? now(),
        ]),
        $specs,
    );
}

test('listing reports requires authentication', function () {
    $this->getJson('/api/v1/reports')
        ->assertUnauthorized()
        ->assertJsonPath('error_code', 'UNAUTHENTICATED');
});

test('a user only sees their own reports', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    phase4aSeedReports($userA, [['category' => ReportTestCategory::Cbc], ['category' => ReportTestCategory::Diabetes]]);
    phase4aSeedReports($userB, [['category' => ReportTestCategory::LiverFunction]]);

    $response = $this->withToken(phase4aToken($userA))->getJson('/api/v1/reports')->assertOk();

    $categories = array_column($response->json('data.reports'), 'test_category');
    expect($categories)->toHaveCount(2)
        ->and($categories)->toContain('CBC', 'DIABETES')
        ->and($categories)->not->toContain('LIVER_FUNCTION');
});

test('user B reports never leak into user A history regardless of filters', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    phase4aSeedReports($userB, [
        ['category' => ReportTestCategory::Cbc, 'status' => ReportStatus::Completed],
        ['category' => ReportTestCategory::Diabetes, 'status' => ReportStatus::Failed],
    ]);

    $response = $this->withToken(phase4aToken($userA))->getJson('/api/v1/reports')->assertOk();

    expect($response->json('data.reports'))->toBe([])
        ->and($response->json('data.pagination.total'))->toBe(0);
});

test('reports are ordered newest first by default', function () {
    $user = User::factory()->create();
    phase4aSeedReports($user, [
        ['category' => ReportTestCategory::Cbc, 'created_at' => now()->subDays(3)],
        ['category' => ReportTestCategory::Diabetes, 'created_at' => now()->subDay()],
        ['category' => ReportTestCategory::LiverFunction, 'created_at' => now()->subDays(5)],
    ]);

    $response = $this->withToken(phase4aToken($user))->getJson('/api/v1/reports')->assertOk();

    expect(array_column($response->json('data.reports'), 'test_category'))
        ->toBe(['DIABETES', 'CBC', 'LIVER_FUNCTION']);
});

test('history is paginated with the requested page size', function () {
    $user = User::factory()->create();
    phase4aSeedReports($user, array_fill(0, 7, ['category' => ReportTestCategory::Cbc]));

    $firstPage = $this->withToken(phase4aToken($user))
        ->getJson('/api/v1/reports?per_page=3')
        ->assertOk();

    expect($firstPage->json('data.reports'))->toHaveCount(3)
        ->and($firstPage->json('data.pagination'))->toMatchArray([
            'current_page' => 1,
            'per_page' => 3,
            'total' => 7,
            'last_page' => 3,
            'has_more' => true,
        ]);

    $lastPage = $this->withToken(phase4aToken($user))
        ->getJson('/api/v1/reports?per_page=3&page=3')
        ->assertOk();

    expect($lastPage->json('data.reports'))->toHaveCount(1)
        ->and($lastPage->json('data.pagination.has_more'))->toBeFalse();
});

test('per_page above the maximum is rejected rather than silently capped', function () {
    $user = User::factory()->create();

    $this->withToken(phase4aToken($user))
        ->getJson('/api/v1/reports?per_page=500')
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'VALIDATION_ERROR');
});

test('history defaults to a reasonable page size when per_page is omitted', function () {
    $user = User::factory()->create();
    phase4aSeedReports($user, array_fill(0, 12, ['category' => ReportTestCategory::Cbc]));

    $response = $this->withToken(phase4aToken($user))->getJson('/api/v1/reports')->assertOk();

    expect($response->json('data.pagination.per_page'))->toBe(10)
        ->and($response->json('data.reports'))->toHaveCount(10);
});

test('history can be filtered by test category', function () {
    $user = User::factory()->create();
    phase4aSeedReports($user, [
        ['category' => ReportTestCategory::Cbc],
        ['category' => ReportTestCategory::Diabetes],
        ['category' => ReportTestCategory::Cbc],
    ]);

    $response = $this->withToken(phase4aToken($user))
        ->getJson('/api/v1/reports?test_category=CBC')
        ->assertOk();

    expect($response->json('data.reports'))->toHaveCount(2)
        ->and(array_column($response->json('data.reports'), 'test_category'))->each->toBe('CBC');
});

test('an invalid test category filter is rejected', function () {
    $user = User::factory()->create();

    $this->withToken(phase4aToken($user))
        ->getJson('/api/v1/reports?test_category=NOT_REAL')
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'VALIDATION_ERROR');
});

test('history can be filtered by status', function () {
    $user = User::factory()->create();
    phase4aSeedReports($user, [
        ['status' => ReportStatus::Completed],
        ['status' => ReportStatus::NeedsReview],
        ['status' => ReportStatus::Completed],
        ['status' => ReportStatus::Failed],
    ]);

    $response = $this->withToken(phase4aToken($user))
        ->getJson('/api/v1/reports?status=COMPLETED')
        ->assertOk();

    expect($response->json('data.reports'))->toHaveCount(2)
        ->and(array_column($response->json('data.reports'), 'status'))->each->toBe('COMPLETED');
});

test('category and status filters combine correctly', function () {
    $user = User::factory()->create();
    phase4aSeedReports($user, [
        ['category' => ReportTestCategory::Cbc, 'status' => ReportStatus::Completed],
        ['category' => ReportTestCategory::Cbc, 'status' => ReportStatus::Failed],
        ['category' => ReportTestCategory::Diabetes, 'status' => ReportStatus::Completed],
    ]);

    $response = $this->withToken(phase4aToken($user))
        ->getJson('/api/v1/reports?test_category=CBC&status=COMPLETED')
        ->assertOk();

    expect($response->json('data.reports'))->toHaveCount(1)
        ->and($response->json('data.reports.0.test_category'))->toBe('CBC')
        ->and($response->json('data.reports.0.status'))->toBe('COMPLETED');
});

test('a user with no reports sees an empty history with correct pagination metadata', function () {
    $user = User::factory()->create();

    $response = $this->withToken(phase4aToken($user))->getJson('/api/v1/reports')->assertOk();

    expect($response->json('data.reports'))->toBe([])
        ->and($response->json('data.pagination'))->toMatchArray([
            'current_page' => 1,
            'total' => 0,
            'last_page' => 1,
            'has_more' => false,
        ]);
});

test('history includes reports across every category and lifecycle status', function () {
    $user = User::factory()->create();
    phase4aSeedReports($user, [
        ['category' => ReportTestCategory::Cbc, 'status' => ReportStatus::Uploaded],
        ['category' => ReportTestCategory::Diabetes, 'status' => ReportStatus::Processing],
        ['category' => ReportTestCategory::LiverFunction, 'status' => ReportStatus::NeedsReview],
        ['category' => ReportTestCategory::Cbc, 'status' => ReportStatus::Failed],
        ['category' => ReportTestCategory::Diabetes, 'status' => ReportStatus::Completed],
    ]);

    $response = $this->withToken(phase4aToken($user))->getJson('/api/v1/reports')->assertOk();

    expect($response->json('data.reports'))->toHaveCount(5);
});

test('the report history response has the expected contract', function () {
    $user = User::factory()->create();
    phase4aSeedReports($user, [['category' => ReportTestCategory::Cbc, 'status' => ReportStatus::Completed]]);

    $this->withToken(phase4aToken($user))
        ->getJson('/api/v1/reports')
        ->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'reports' => [
                    '*' => ['id', 'test_category', 'source_type', 'status', 'report_date', 'created_at', 'updated_at'],
                ],
                'pagination' => ['current_page', 'per_page', 'total', 'last_page', 'has_more'],
            ],
        ])
        ->assertJsonMissingPath('data.reports.0.patient_age_years')
        ->assertJsonMissingPath('data.reports.0.patient_sex');
});

test('listing history performs no writes and has no side effects', function () {
    Queue::fake();
    $user = User::factory()->create();
    phase4aSeedReports($user, [['category' => ReportTestCategory::Cbc, 'status' => ReportStatus::NeedsReview]]);

    $beforeAnalyses = Analysis::query()->count();
    $beforeQuizSessions = QuizSession::query()->count();
    $beforeReports = Report::query()->count();

    $this->withToken(phase4aToken($user))->getJson('/api/v1/reports')->assertOk();

    expect(Analysis::query()->count())->toBe($beforeAnalyses)
        ->and(QuizSession::query()->count())->toBe($beforeQuizSessions)
        ->and(Report::query()->count())->toBe($beforeReports);
    Queue::assertNothingPushed();
    Queue::assertNotPushed(ProcessReportOcr::class);
    Queue::assertNotPushed(ProcessReportAnalysis::class);
});
