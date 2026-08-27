<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Health check endpoint
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'service' => 'auth-service',
            'status' => 'healthy',
        ]);
    }

    /**
     * Register a new user
     */
    public function register(StoreUserRequest $request): JsonResponse
    {
        try {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
            ]);

            Log::info('User registered', [
                'service' => 'auth-service',
                'correlation_id' => $request->attributes->get('correlation_id'),
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('User registration failed', [
                'service' => 'auth-service',
                'correlation_id' => $request->attributes->get('correlation_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
            ], 500);
        }
    }

    /**
     * Login user
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = User::where('email', $request->validated('email'))->first();

            if (!$user || !Hash::check($request->validated('password'), $user->password)) {
                Log::warning('Invalid login attempt', [
                    'service' => 'auth-service',
                    'correlation_id' => $request->attributes->get('correlation_id'),
                    'email' => $request->validated('email'),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            // Create a new personal access token
            $tokenData = $user->createToken('auth-token');

            Log::info('User logged in', [
                'service' => 'auth-service',
                'correlation_id' => $request->attributes->get('correlation_id'),
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'token' => $tokenData['plain_text_token'],
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('User login failed', [
                'service' => 'auth-service',
                'correlation_id' => $request->attributes->get('correlation_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Login failed',
            ], 500);
        }
    }

    /**
     * Get current authenticated user
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get user',
            ], 500);
        }
    }

    /**
     * Logout current user
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Delete all tokens for the user
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
            ], 500);
        }
    }
}
