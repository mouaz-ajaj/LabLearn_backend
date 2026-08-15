<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\StoreReportVerificationRequest;
use App\Http\Resources\VerifiedResultSetResource;
use App\Models\Report;
use App\Services\Verification\VerifyReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreReportVerificationController extends Controller
{
    public function __invoke(StoreReportVerificationRequest $request, Report $report, VerifyReport $verifyReport): JsonResponse
    {
        Gate::authorize('update', $report);
        $set = $verifyReport->handle($report, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Report values verified successfully.',
            'data' => [
                'report_id' => $report->getKey(),
                'report_status' => 'VERIFIED',
                'verified_result_set' => VerifiedResultSetResource::make($set)->resolve($request),
                'next_actions' => $verifyReport->nextActions($set, $request->user()),
                'kbs_integration_ready' => true,
            ],
        ], 201);
    }
}
