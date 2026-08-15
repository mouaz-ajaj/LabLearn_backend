<?php

namespace App\Http\Controllers\Api\Job;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExtractionJobResource;
use App\Models\ExtractionJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExtractionJobController extends Controller
{
    public function __invoke(Request $request, ExtractionJob $job): JsonResponse
    {
        Gate::authorize('view', $job);

        return response()->json([
            'success' => true,
            'data' => ExtractionJobResource::make($job)->resolve($request),
        ]);
    }
}
