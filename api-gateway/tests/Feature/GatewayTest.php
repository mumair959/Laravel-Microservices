<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Fake all HTTP requests
        Http::preventStrayRequests();
    }

    /**
     * Test health endpoint
     */
    public function test_health_endpoint(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'service' => 'api-gateway',
                'status' => 'healthy',
            ]);
    }

    /**
     * Test forwarding GET request to product service
     */
    public function test_get_products_forwards_to_product_service(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Product 1', 'price' => 100],
                    ['id' => 2, 'name' => 'Product 2', 'price' => 200],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'price'],
                ],
            ]);


    }

    /**
     * Test forwarding POST request to product service
     */
    public function test_create_product_forwards_to_product_service(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products' => Http::response([
                'data' => ['id' => 1, 'name' => 'New Product', 'price' => 150],
            ], 201),
        ]);

        $response = $this->postJson('/api/v1/products', [
            'name' => 'New Product',
            'price' => 150,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'price'],
            ]);
    }

    /**
     * Test forwarding GET request with ID to product service
     */
    public function test_get_product_by_id_forwards_to_product_service(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products/1' => Http::response([
                'data' => ['id' => 1, 'name' => 'Product 1', 'price' => 100],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/products/1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'price'],
            ]);
    }

    /**
     * Test forwarding PUT request to product service
     */
    public function test_update_product_forwards_to_product_service(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products/1' => Http::response([
                'data' => ['id' => 1, 'name' => 'Updated Product', 'price' => 200],
            ], 200),
        ]);

        $response = $this->putJson('/api/v1/products/1', [
            'name' => 'Updated Product',
            'price' => 200,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'price'],
            ]);
    }

    /**
     * Test forwarding DELETE request to product service
     */
    public function test_delete_product_forwards_to_product_service(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products/1' => Http::response([], 204),
        ]);

        $response = $this->deleteJson('/api/v1/products/1');

        $response->assertStatus(204);
    }

    /**
     * Test forwarding GET request to order service
     */
    public function test_get_orders_forwards_to_order_service(): void
    {
        Http::fake([
            'http://127.0.0.1:8002/api/v1/orders' => Http::response([
                'data' => [
                    ['id' => 1, 'customer' => 'John', 'total' => 500],
                    ['id' => 2, 'customer' => 'Jane', 'total' => 750],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'customer', 'total'],
                ],
            ]);
    }

    /**
     * Test forwarding POST request to order service
     */
    public function test_create_order_forwards_to_order_service(): void
    {
        Http::fake([
            'http://127.0.0.1:8002/api/v1/orders' => Http::response([
                'data' => ['id' => 1, 'customer' => 'John', 'total' => 500],
            ], 201),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'customer' => 'John',
            'total' => 500,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'customer', 'total'],
            ]);
    }

    /**
     * Test forwarding GET request with ID to order service
     */
    public function test_get_order_by_id_forwards_to_order_service(): void
    {
        Http::fake([
            'http://127.0.0.1:8002/api/v1/orders/1' => Http::response([
                'data' => ['id' => 1, 'customer' => 'John', 'total' => 500],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/orders/1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'customer', 'total'],
            ]);
    }

    /**
     * Test downstream 404 response
     */
    public function test_downstream_404_is_forwarded(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products/999' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $response = $this->getJson('/api/v1/products/999');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Not Found']);
    }

    /**
     * Test downstream service unavailable
     */
    public function test_downstream_service_unavailable_returns_503(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products' => Http::response([], 503),
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(503);
    }

    /**
     * Test request with query parameters are forwarded
     */
    public function test_query_parameters_are_forwarded(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/v1/products?page=1&limit=10' => Http::response([
                'data' => [
                    ['id' => 1, 'name' => 'Product 1', 'price' => 100],
                ],
            ], 200),
        ]);

        $response = $this->getJson('/api/v1/products?page=1&limit=10');

        $response->assertStatus(200);
    }
}
