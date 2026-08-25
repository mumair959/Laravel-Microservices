<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderOwnershipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test order creation stores authenticated user_id
     */
    public function test_order_creation_stores_authenticated_user_id(): void
    {
        Http::fake([
            config('services.product_service_url') . '/api/v1/products/1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 1,
                    'name' => 'Test Product',
                    'price' => 100.00,
                    'stock' => 10,
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 2,
                ],
            ],
        ], [
            'X-User-Id' => '15',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        // Verify order was created with the authenticated user_id
        $this->assertDatabaseHas('orders', [
            'user_id' => 15,
            'status' => 'pending',
        ]);
    }

    /**
     * Test request body cannot override user_id
     */
    public function test_request_body_cannot_override_user_id(): void
    {
        Http::fake([
            config('services.product_service_url') . '/api/v1/products/1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 1,
                    'name' => 'Test Product',
                    'price' => 100.00,
                    'stock' => 10,
                ],
            ], 200),
        ]);

        // Client tries to claim they are user 999
        $response = $this->postJson('/api/v1/orders', [
            'user_id' => 999,
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 2,
                ],
            ],
        ], [
            'X-User-Id' => '15',
        ]);

        $response->assertStatus(201);

        // Verify order was created with the authenticated user_id, NOT 999
        $this->assertDatabaseHas('orders', [
            'user_id' => 15,
        ]);
        $this->assertDatabaseMissing('orders', [
            'user_id' => 999,
        ]);
    }

    /**
     * Test user can list their own orders
     */
    public function test_user_can_list_their_own_orders(): void
    {
        // Create orders for user 15
        Order::create([
            'user_id' => 15,
            'status' => 'pending',
            'total_amount' => 100.00,
        ]);
        Order::create([
            'user_id' => 15,
            'status' => 'completed',
            'total_amount' => 200.00,
        ]);

        // Create order for different user
        Order::create([
            'user_id' => 20,
            'status' => 'pending',
            'total_amount' => 150.00,
        ]);

        $response = $this->getJson('/api/v1/orders', [], [
            'X-User-Id' => '15',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.data.0.user_id', 15);
        $response->assertJsonPath('data.data.1.user_id', 15);
        
        // Should have exactly 2 orders for this user
        $this->assertEquals(2, count($response->json('data.data')));
    }

    /**
     * Test user cannot see another user's orders
     */
    public function test_user_cannot_see_another_users_orders(): void
    {
        // Create orders for user 15
        Order::create([
            'user_id' => 15,
            'status' => 'pending',
            'total_amount' => 100.00,
        ]);

        // Create order for different user
        Order::create([
            'user_id' => 20,
            'status' => 'pending',
            'total_amount' => 150.00,
        ]);

        // User 20 lists their orders
        $response = $this->getJson('/api/v1/orders', [], [
            'X-User-Id' => '20',
        ]);

        $response->assertStatus(200);
        
        // Should only see their own order, not user 15's
        $this->assertEquals(1, count($response->json('data.data')));
        $this->assertEquals(20, $response->json('data.data.0.user_id'));
    }

    /**
     * Test user can view their own order
     */
    public function test_user_can_view_their_own_order(): void
    {
        $order = Order::create([
            'user_id' => 15,
            'status' => 'pending',
            'total_amount' => 100.00,
        ]);

        $response = $this->getJson("/api/v1/orders/{$order->id}", [], [
            'X-User-Id' => '15',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $order->id);
        $response->assertJsonPath('data.user_id', 15);
    }

    /**
     * Test user cannot view another user's order
     */
    public function test_user_cannot_view_another_users_order(): void
    {
        $order = Order::create([
            'user_id' => 15,
            'status' => 'pending',
            'total_amount' => 100.00,
        ]);

        // User 20 tries to view user 15's order
        $response = $this->getJson("/api/v1/orders/{$order->id}", [], [
            'X-User-Id' => '20',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    /**
     * Test missing X-User-Id results in authentication error
     */
    public function test_missing_x_user_id_results_in_authentication_error(): void
    {
        $response = $this->getJson('/api/v1/orders', []);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Unauthenticated');
    }

    /**
     * Test unauthenticated order creation returns 401
     */
    public function test_unauthenticated_order_creation_returns_401(): void
    {
        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 2,
                ],
            ],
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Unauthenticated');
    }
}
