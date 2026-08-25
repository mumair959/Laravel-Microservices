<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_can_be_listed_and_shown(): void
    {
        $order = Order::create(['status' => 'pending', 'total_amount' => 25]);

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('success', true);
        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }

    public function test_order_uses_current_product_data_and_calculates_totals(): void
    {
        Http::fake([
            '127.0.0.1:8001/api/v1/products/1' => Http::response([
                'success' => true,
                'data' => ['id' => 1, 'name' => 'Laptop', 'price' => '12.50', 'stock' => 4],
            ]),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => 1, 'quantity' => 2]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total_amount', '25.00')
            ->assertJsonPath('data.items.0.product_name', 'Laptop')
            ->assertJsonPath('data.items.0.unit_price', '12.50');
        Http::assertSentCount(1);
    }

    public function test_invalid_order_request_is_rejected(): void
    {
        $this->postJson('/api/v1/orders', ['items' => [['product_id' => 1, 'quantity' => 0]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_missing_product_returns_not_found(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'data' => null], 404)]);

        $this->postJson('/api/v1/orders', ['items' => [['product_id' => 99, 'quantity' => 1]]])
            ->assertNotFound();
    }

    public function test_insufficient_stock_returns_unprocessable_entity(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'id' => 1, 'name' => 'Laptop', 'price' => '12.50', 'stock' => 1,
        ]])]);

        $this->postJson('/api/v1/orders', ['items' => [['product_id' => 1, 'quantity' => 2]]])
            ->assertUnprocessable();
    }

    public function test_product_service_unavailability_returns_service_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Product service unavailable.'));

        $this->postJson('/api/v1/orders', ['items' => [['product_id' => 1, 'quantity' => 1]]])
            ->assertStatus(503);
    }

    public function test_missing_order_returns_not_found(): void
    {
        $this->getJson('/api/v1/orders/999999')->assertNotFound();
    }
}