<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithAuthService
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get the Authorization header
        $header = $request->header('Authorization');

        if (!$header) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Extract the token from "Bearer <token>"
        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $plainToken = substr($header, 7); // Remove "Bearer " prefix

        if (empty($plainToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            // Call Auth Service to validate token
            $response = Http::timeout(config('services.http_timeout', 10))
                ->connectTimeout(config('services.http_connect_timeout', 5))
                ->withHeaders([
                    'Authorization' => "Bearer {$plainToken}",
                    'X-Correlation-ID' => $request->header('X-Correlation-ID', \Ramsey\Uuid\Uuid::uuid4()->toString()),
                ])
                ->get(config('services.auth_service_url') . '/api/v1/auth/me');

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $data = $response->json();
            if (!$data['success'] || !isset($data['data']['user']['id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Store authenticated user info in request
            $user = $data['data']['user'];
            $request->merge([
                'authenticated_user_id' => $user['id'],
                'authenticated_user_email' => $user['email'] ?? null,
                'authenticated_user_name' => $user['name'] ?? null,
            ]);

        } catch (\Exception $e) {
            // Auth Service is unavailable
            return response()->json([
                'success' => false,
                'message' => 'Service unavailable',
            ], 503);
        }

        return $next($request);
    }
}
