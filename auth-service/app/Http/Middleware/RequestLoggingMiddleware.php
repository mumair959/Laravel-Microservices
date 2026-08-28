<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequestLoggingMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip logging health checks to avoid log spam
        if ($request->is('*/health') || $request->is('up')) {
            return $next($request);
        }

        $startTime = microtime(true);
        $correlationId = $request->attributes->get('correlation_id', 'unknown');

        // Log request received
        Log::info('HTTP request received', [
            'service' => config('app.service_name', 'auth-service'),
            'correlation_id' => $correlationId,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'ip' => $request->ip(),
        ]);

        // Process the request
        $response = $next($request);

        // Calculate duration
        $duration = (microtime(true) - $startTime) * 1000;

        // Log response
        Log::info('HTTP request completed', [
            'service' => config('app.service_name', 'auth-service'),
            'correlation_id' => $correlationId,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($duration, 2),
        ]);

        return $response;
    }
}
