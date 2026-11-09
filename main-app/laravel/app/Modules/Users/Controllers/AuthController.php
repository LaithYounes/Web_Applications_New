<?php

namespace App\Modules\Users\Controllers;

use App\Http\ApiErrorResponse;
use App\Http\Controllers\Controller;
use App\Modules\Users\Requests\LoginRequest;
use App\Modules\Users\Requests\RegisterRequest;
use App\Modules\Users\Resources\UserResource;
use App\Modules\Users\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully',
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => 'bearer',
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        if (!$result) {
            return ApiErrorResponse::make(401, 'Invalid email or password');
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => 'bearer',
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Authenticated user retrieved successfully',
            'data' => [
                'user' => new UserResource(
                    $this->authService->getAuthenticatedUser()
                ),
            ],
        ]);
    }
}
