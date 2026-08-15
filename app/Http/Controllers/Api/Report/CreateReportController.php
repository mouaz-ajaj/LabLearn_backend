<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\User;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CreateReportController extends Controller
{
    public function __construct(private readonly ReportService $reportService) {}

    public function __invoke(StoreReportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $report = $this->reportService->create($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Report created successfully.',
            'data' => ['report' => ReportResource::make($report)->resolve($request)],
        ], Response::HTTP_CREATED);
    }
}
