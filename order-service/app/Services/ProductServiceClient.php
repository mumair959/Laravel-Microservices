<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductServiceClient
{
    public function getProduct(int $productId): Response
    {
        $correlationId = request()->attributes->get('correlation_id', '');
        $startTime = microtime(true);
        
        try {
            Log::info('Service-to-service request initiated', [
                'target_service' => 'product-service',
                'correlation_id' => $correlationId,
                'method' => 'GET',
                'path' => "/api/v1/products/{$productId}",
            ]);

            $response = Http::timeout(config('services.http_timeout', 10))
                ->connectTimeout(config('services.http_connect_timeout', 5))
                ->acceptJson()
                ->when($correlationId, fn ($client) => $client->withHeaders(['X-Correlation-ID' => $correlationId]))
                ->get(rtrim(config('services.product_service.url'), '/')."/api/v1/products/{$productId}");

            $duration = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                Log::info('Service-to-service request succeeded', [
                    'target_service' => 'product-service',
                    'correlation_id' => $correlationId,
                    'product_id' => $productId,
                    'status' => $response->status(),
                    'duration_ms' => round($duration, 2),
                ]);
            } else {
                Log::warning('Service-to-service request failed', [
                    'target_service' => 'product-service',
                    'correlation_id' => $correlationId,
                    'product_id' => $productId,
                    'status' => $response->status(),
                    'duration_ms' => round($duration, 2),
                ]);
            }

            return $response;
        } catch (ConnectionException $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            Log::error('Service-to-service request connection failed', [
                'target_service' => 'product-service',
                'correlation_id' => $correlationId,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration, 2),
            ]);

            throw $e;
        }
    }
}