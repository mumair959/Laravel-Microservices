<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    /**
     * Test request logging middleware logs requests
     */
    public function test_request_logging_middleware_logs_requests(): void
    {
        Log::shouldReceive('info')
            ->atLeast()->once()
            ->with('HTTP request received', \Mockery::subset([
                'service' => 'order-service',
                'method' => 'GET',
                'path' => '/api/v1/orders',
            ]))
            ->andReturnNull();

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->with('HTTP request completed', \Mockery::subset([
                'service' => 'order-service',
                'status' => 200,
            ]))
            ->andReturnNull();

        $this->getJson('/api/v1/orders', [
            'X-User-Id' => '1',
        ]);
    }

    /**
     * Test correlation ID is included in request logs
     */
    public function test_correlation_id_is_included_in_request_logs(): void
    {
        $customCorrelationId = 'test-correlation-' . uniqid();

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->with('HTTP request received', \Mockery::subset([
                'correlation_id' => $customCorrelationId,
            ]))
            ->andReturnNull();

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->with('HTTP request completed', \Mockery::subset([
                'correlation_id' => $customCorrelationId,
            ]))
            ->andReturnNull();

        $this->getJson('/api/v1/orders', [
            'X-User-Id' => '1',
            'X-Correlation-ID' => $customCorrelationId,
        ]);
    }

    /**
     * Test request duration is logged in milliseconds
     */
    public function test_request_duration_is_logged_in_milliseconds(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('HTTP request completed', \Mockery::on(function ($context) {
                return isset($context['duration_ms']) &&
                       is_numeric($context['duration_ms']) &&
                       $context['duration_ms'] >= 0;
            }))
            ->andReturnNull();

        Http::fake([
            'http://127.0.0.1:8001/api/v1/products/1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 1,
                    'name' => 'Test Product',
                    'price' => 100.00,
                    'stock' => 10,
                ],
            ]),
        ]);

        $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 1,
                ],
            ],
        ], [
            'X-User-Id' => '1',
        ]);
    }

    /**
     * Test correlation ID is preserved across request and response
     */
    public function test_correlation_id_preserved_in_response(): void
    {
        $customCorrelationId = 'test-trace-' . uniqid();

        Http::fake([
            'http://127.0.0.1:8001/api/v1/products/1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 1,
                    'name' => 'Test Product',
                    'price' => 100.00,
                    'stock' => 10,
                ],
            ]),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 1,
                ],
            ],
        ], [
            'X-User-Id' => '1',
            'X-Correlation-ID' => $customCorrelationId,
        ]);

        $this->assertEquals($customCorrelationId, $response->header('X-Correlation-ID'));
    }

    /**
     * Test service name is logged
     */
    public function test_service_name_is_logged(): void
    {
        Log::shouldReceive('info')
            ->atLeast()->once()
            ->with('HTTP request received', \Mockery::subset([
                'service' => 'order-service',
            ]))
            ->andReturnNull();

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->with('HTTP request completed', \Mockery::subset([
                'service' => 'order-service',
            ]))
            ->andReturnNull();

        $this->getJson('/api/v1/orders', [
            'X-User-Id' => '1',
        ]);
    }

    /**
     * Test health endpoint does not generate excessive logs
     */
    public function test_health_endpoint_does_not_generate_request_logs(): void
    {
        Log::shouldReceive('info')
            ->with('HTTP request received', \Mockery::any())
            ->never();

        Log::shouldReceive('info')
            ->with('HTTP request completed', \Mockery::any())
            ->never();

        $this->getJson('/api/health');
    }
}
