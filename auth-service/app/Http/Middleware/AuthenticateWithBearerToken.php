<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithBearerToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
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

        // Hash the token to compare with stored hash
        $hashedToken = hash('sha256', $plainToken);

        // Find the token in the database
        $tokenModel = \App\Models\PersonalAccessToken::where('token', $hashedToken)->first();

        if (!$tokenModel) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Get the associated user
        $user = $tokenModel->tokenable;

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Set the authenticated user on the request
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        // Update last_used_at
        $tokenModel->update(['last_used_at' => now()]);

        return $next($request);
    }
}
