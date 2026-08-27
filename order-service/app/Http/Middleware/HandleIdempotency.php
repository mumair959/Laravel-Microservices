<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleIdempotency
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply idempotency to POST requests to store orders
        if ($request->method() !== 'POST' || !str_contains($request->getPathInfo(), '/v1/orders')) {
            return $next($request);
        }

        // Get Idempotency-Key header
        $idempotencyKey = $request->header('Idempotency-Key');
        
        if (!$idempotencyKey) {
            // Idempotency-Key is optional but recommended
            return $next($request);
        }

        // Get authenticated user ID
        $userId = $request->header('X-User-Id');
        if (!$userId) {
            return $next($request);
        }

        // Calculate request hash
        $requestHash = hash('sha256', $request->getContent());

        // Check if this idempotency key has been used before
        $stored = IdempotencyKey::where('user_id', $userId)
            ->where('key', $idempotencyKey)
            ->first();

        if ($stored) {
            // Key has been used before
            if ($stored->request_hash === $requestHash) {
                // Same request - return the cached response
                return response()
                    ->json($stored->response_body, $stored->response_status)
                    ->header('Idempotency-Replayed', 'true');
            } else {
                // Different request with same key - conflict
                return response()->json([
                    'success' => false,
                    'message' => 'Idempotency key has already been used with a different request.',
                ], 409);
            }
        }

        // Key hasn't been used - store for later retrieval
        $request->attributes->set('idempotency_key', $idempotencyKey);
        $request->attributes->set('request_hash', $requestHash);

        return $next($request);
    }
}
