<?php

namespace App\Http\Controllers\Api\Report;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\VerifiedResultSetResource;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ShowReportVerificationController extends Controller
{
    public function __invoke(Request $request, Report $report): JsonResponse
    {
        Gate::authorize('view', $report);
        $query = $report->verifiedResultSets()->with('results');
        $set = $request->filled('version')
            ? $query->where('version', $request->integer('version'))->first()
            : $query->latest('version')->first();
        if ($set === null) {
            throw new ApiException('VERIFIED_RESULTS_NOT_FOUND', 'No verified result set was found.', 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'report_id' => $report->getKey(),
                'report_status' => $report->status->value,
                'verified_result_set' => VerifiedResultSetResource::make($set)->resolve($request),
            ],
        ]);
    }
}
