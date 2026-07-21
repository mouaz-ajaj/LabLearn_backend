<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RegisterController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $authentication = $this->authService->register($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Registration successful.',
            'data' => [
                'token' => $authentication['token'],
                'token_type' => 'Bearer',
                'user' => UserResource::make($authentication['user'])->resolve($request),
            ],
        ], Response::HTTP_CREATED);
    }
}
