<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CorrelationIdTest extends TestCase
{
    public function test_correlation_id_is_generated_when_not_provided(): void
    {
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
        ]);

        // Check that response has correlation ID header
        $this->assertTrue($response->headers->has('X-Correlation-ID'));
        $correlationId = $response->headers->get('X-Correlation-ID');
        $this->assertNotEmpty($correlationId);
    }

    public function test_correlation_id_is_preserved_when_provided(): void
    {
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

        $customCorrelationId = 'test-correlation-123';

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

        // Check that response has the same correlation ID header
        $this->assertEquals($customCorrelationId, $response->headers->get('X-Correlation-ID'));
    }

    public function test_correlation_id_is_passed_to_downstream_services(): void
    {
        $customCorrelationId = 'test-correlation-456';

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

        $this->assertTrue($response->ok());

        // Verify the request to Product Service included the correlation ID
        Http::assertSent(function ($request) use ($customCorrelationId) {
            return $request->url() === 'http://127.0.0.1:8001/api/v1/products/1' &&
                   $request->header('X-Correlation-ID') === $customCorrelationId;
        });
    }
}
