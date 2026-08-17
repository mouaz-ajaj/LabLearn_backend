<?php

namespace App\Services\Comparison;

use App\Exceptions\ApiException;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4C, step 1: authorization + same-category compatibility validation +
 * chronological ordering. Nothing here computes a trend or touches OCR/KBS - it only
 * decides whether the requested report_ids are even eligible to be compared, and in
 * what order. Ownership failures use the same 403 FORBIDDEN convention as every other
 * report-scoped endpoint in this project (ShowReportController, ExtractedResultController,
 * ...) rather than masking as 404 - this project does not attempt anti-enumeration.
 */
class ResolveComparableReports
{
    /**
     * @param  int[]  $reportIds
     * @return Report[] oldest -> newest
     */
    public function handle(User $user, array $reportIds): array
    {
        $reports = Report::query()->whereIn('id', $reportIds)->get()->keyBy('id');

        foreach ($reportIds as $id) {
            $report = $reports->get($id);
            if ($report === null) {
                throw (new ModelNotFoundException)->setModel(Report::class, [$id]);
            }
            Gate::authorize('view', $report);
        }

        $categories = $reports->pluck('test_category')->map(fn ($category) => $category->value)->unique();
        if ($categories->count() > 1) {
            throw new ApiException(
                'COMPARISON_CATEGORY_MISMATCH',
                'Only reports of the same test category can be compared.',
                409,
                ['categories' => $categories->values()->all()],
            );
        }

        foreach ($reports as $report) {
            if (! $report->verifiedResultSets()->exists()) {
                throw new ApiException(
                    'COMPARISON_REPORT_NOT_VERIFIED',
                    'Every selected report must have at least one verified result set before it can be compared.',
                    409,
                    ['report_id' => $report->getKey()],
                );
            }
        }

        return $reports->sortBy('created_at')->values()->all();
    }
}
