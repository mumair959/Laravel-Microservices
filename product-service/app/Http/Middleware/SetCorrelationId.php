<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

class SetCorrelationId
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get or generate correlation ID
        $correlationId = $request->header('X-Correlation-ID');
        
        if (!$correlationId || !$this->isValidUuid($correlationId)) {
            $correlationId = Uuid::uuid4()->toString();
        }
        
        // Store in request for logging
        $request->attributes->set('correlation_id', $correlationId);
        
        // Set global log context
        Log::withContext([
            'service' => config('app.service_name', 'product-service'),
            'correlation_id' => $correlationId,
        ]);
        
        // Process the request
        $response = $next($request);
        
        // Add correlation ID to response headers
        $response->header('X-Correlation-ID', $correlationId);
        
        return $response;
    }

    /**
     * Validate if a string is a valid UUID.
     */
    private function isValidUuid(string $uuid): bool
    {
        try {
            Uuid::fromString($uuid);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
