<?php

namespace Tests\Feature;

use App\Jobs\PublishOrderCreated;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure we're testing against the database queue driver
        config(['queue.default' => 'database']);
    }

    /**
     * Test order creation dispatches PublishOrderCreated job
     */
    public function test_order_creation_dispatches_publish_order_created_job(): void
    {
        Queue::fake();

        Http::fake([
            config('services.product_service.url') . '/api/v1/products/1' => Http::response([
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
        $this->assertDatabaseHas('orders', ['user_id' => 15, 'status' => 'pending']);

        // Verify job was dispatched
        Queue::assertPushed(PublishOrderCreated::class);
    }

    /**
     * Test OrderCreated event contains correct data
     */
    public function test_order_created_event_contains_correct_data(): void
    {
        Queue::fake();

        Http::fake([
            config('services.product_service.url') . '/api/v1/products/1' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 1,
                    'name' => 'Test Product A',
                    'price' => 99.99,
                    'stock' => 10,
                ],
            ], 200),
            config('services.product_service.url') . '/api/v1/products/2' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 2,
                    'name' => 'Test Product B',
                    'price' => 149.99,
                    'stock' => 5,
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                [
                    'product_id' => 1,
                    'quantity' => 2,
                ],
                [
                    'product_id' => 2,
                    'quantity' => 1,
                ],
            ],
        ], [
            'X-User-Id' => '15',
        ]);

        $response->assertStatus(201);
        $order = Order::first();

        Queue::assertPushed(PublishOrderCreated::class, function ($job) use ($order) {
            $reflection = new \ReflectionClass($job);
            $orderIdProperty = $reflection->getProperty('order_id');
            $orderIdProperty->setAccessible(true);
            
            $userIdProperty = $reflection->getProperty('user_id');
            $userIdProperty->setAccessible(true);
            
            return $orderIdProperty->getValue($job) === $order->id
                && $userIdProperty->getValue($job) === 15;
        });
    }

    /**
     * Test PublishOrderCreated job makes HTTP request to Product Service
     */
    public function test_publish_order_created_job_makes_http_request(): void
    {
        Http::fake();

        $order = Order::create([
            'user_id' => 15,
            'status' => 'pending',
            'total_amount' => 250.00,
        ]);

        $job = new PublishOrderCreated(
            event_id: 'test-event-123',
            order_id: $order->id,
            user_id: 15,
            items: [['product_id' => 1, 'quantity' => 2]],
            total_amount: 250.00,
        );

        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === config('services.product_service.url') . '/api/internal/events/order-created'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Service-Secret')
                && $request->header('X-Service-Secret') === config('services.product_service.event_secret');
        });
    }

    /**
     * Test PublishOrderCreated job retries on failure
     */
    public function test_publish_order_created_job_retries_on_connection_failure(): void
    {
        Http::fake([
            config('services.product_service.url') . '/api/internal/events/order-created' => Http::response([], 500),
        ]);

        $order = Order::create([
            'user_id' => 15,
            'status' => 'pending',
            'total_amount' => 250.00,
        ]);

        $job = new PublishOrderCreated(
            event_id: 'test-event-123',
            order_id: $order->id,
            user_id: 15,
            items: [['product_id' => 1, 'quantity' => 2]],
            total_amount: 250.00,
        );

        // Job should throw exception to trigger retry
        $this->expectException(\Exception::class);
        $job->handle();
    }

    /**
     * Test PublishOrderCreated job sends correct payload
     */
    public function test_publish_order_created_job_sends_correct_payload(): void
    {
        Http::fake();

        $order = Order::create([
            'user_id' => 15,
            'status' => 'pending',
            'total_amount' => 250.00,
        ]);

        $eventId = 'test-event-id-123';
        $items = [
            ['product_id' => 1, 'quantity' => 2],
            ['product_id' => 5, 'quantity' => 1],
        ];

        $job = new PublishOrderCreated(
            event_id: $eventId,
            order_id: $order->id,
            user_id: 15,
            items: $items,
            total_amount: 250.00,
        );

        $job->handle();

        Http::assertSent(function ($request) use ($eventId, $order) {
            $data = $request->json();
            return $data['event_id'] === $eventId
                && $data['event'] === 'OrderCreated'
                && $data['order_id'] === $order->id
                && $data['user_id'] === 15
                && count($data['items']) === 2
                && $data['total_amount'] === 250.00;
        });
    }
}
