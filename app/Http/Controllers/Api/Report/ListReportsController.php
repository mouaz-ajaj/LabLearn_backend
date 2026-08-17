<?php

namespace App\Http\Controllers\Api\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Report\ListReportsRequest;
use App\Http\Resources\ReportHistoryResource;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ListReportsController extends Controller
{
    public function __invoke(ListReportsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Report::query()->where('user_id', $user->getKey());

        if ($testCategory = $request->validated('test_category')) {
            $query->where('test_category', $testCategory);
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('created_at')->paginate(
            perPage: $request->perPage(),
            page: (int) ($request->validated('page') ?? 1),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'reports' => ReportHistoryResource::collection($paginator->items())->resolve($request),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ],
        ]);
    }
}
