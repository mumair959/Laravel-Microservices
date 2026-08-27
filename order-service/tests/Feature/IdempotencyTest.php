<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    public function test_identical_requests_with_same_idempotency_key_return_same_response(): void
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

        $idempotencyKey = 'test-idempotency-key-123';

        // First request
        $response1 = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 1,
                ],
            ],
        ], [
            'X-User-Id' => '1',
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $this->assertTrue($response1->ok());
        $data1 = $response1->json()['data'];

        // Second identical request with same key
        $response2 = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 1,
                ],
            ],
        ], [
            'X-User-Id' => '1',
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $this->assertTrue($response2->ok());
        $data2 = $response2->json()['data'];

        // Both should have the same order ID
        $this->assertEquals($data1['id'], $data2['id']);

        // Second response should be marked as replayed
        $this->assertEquals('true', $response2->headers->get('Idempotency-Replayed'));
    }

    public function test_different_requests_with_same_idempotency_key_return_409(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products/1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 1,
                    'name' => 'Test Product 1',
                    'price' => 100.00,
                    'stock' => 10,
                ],
            ]),
            'http://127.0.0.1:8001/api/v1/products/2' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 2,
                    'name' => 'Test Product 2',
                    'price' => 50.00,
                    'stock' => 20,
                ],
            ]),
        ]);

        $idempotencyKey = 'test-idempotency-key-456';

        // First request with product 1
        $response1 = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 1,
                ],
            ],
        ], [
            'X-User-Id' => '1',
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $this->assertTrue($response1->ok());

        // Second request with different product but same key
        $response2 = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 2,
                    'quantity' => 1,
                ],
            ],
        ], [
            'X-User-Id' => '1',
            'Idempotency-Key' => $idempotencyKey,
        ]);

        // Should return 409 Conflict
        $this->assertEquals(409, $response2->status());
        $this->assertFalse($response2->json('success'));
    }
}
