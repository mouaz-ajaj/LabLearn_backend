<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $authentication = $this->authService->login(
            $request->validated('email'),
            $request->validated('password'),
        );

        if ($authentication === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
                'error_code' => 'INVALID_CREDENTIALS',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'token' => $authentication['token'],
                'token_type' => 'Bearer',
                'user' => UserResource::make($authentication['user'])->resolve($request),
            ],
        ]);
    }
}
