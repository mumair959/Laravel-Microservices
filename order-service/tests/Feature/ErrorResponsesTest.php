<?php

namespace Tests\Feature;

use Tests\TestCase;

class ErrorResponsesTest extends TestCase
{
    public function test_validation_error_returns_422_with_consistent_format(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'items' => [], // Empty items should fail validation
        ], [
            'X-User-Id' => '1',
        ]);

        $this->assertEquals(422, $response->status());
        $this->assertFalse($response->json('success'));
        $this->assertEquals('Validation failed.', $response->json('message'));
        $this->assertIsArray($response->json('errors'));
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertEquals(401, $response->status());
        $this->assertFalse($response->json('success'));
        $this->assertEquals('Unauthenticated', $response->json('message'));
    }

    public function test_product_not_found_returns_404(): void
    {
        $this->withoutExceptionHandling();

        // Fake the Product Service response
        \Illuminate\Support\Facades\Http::fake([
            'http://127.0.0.1:8001/api/v1/products/999' => \Illuminate\Support\Facades\Http::response([
                'success' => false,
                'data' => null,
            ], 404),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 999,
                    'quantity' => 1,
                ],
            ],
        ], [
            'X-User-Id' => '1',
        ]);

        $this->assertEquals(404, $response->status());
        $this->assertFalse($response->json('success'));
    }
}
