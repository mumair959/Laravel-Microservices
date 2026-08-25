<?php

namespace Tests\Feature;

use App\Jobs\ProcessOrderCreated;
use App\Models\ProcessedEvent;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductEventProcessingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test internal event endpoint accepts valid request
     */
    public function test_internal_event_endpoint_accepts_valid_request(): void
    {
        Queue::fake();

        $payload = [
            'event_id' => 'abc-123-def-456',
            'event' => 'OrderCreated',
            'order_id' => 100,
            'user_id' => 15,
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
            ],
            'total_amount' => 250.00,
        ];

        $response = $this->postJson('/api/internal/events/order-created', $payload, [
            'X-Service-Secret' => config('services.order_service.event_secret'),
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Event accepted');
    }

    /**
     * Test invalid service secret returns 401
     */
    public function test_invalid_service_secret_returns_401(): void
    {
        $payload = [
            'event_id' => 'abc-123-def-456',
            'event' => 'OrderCreated',
            'order_id' => 100,
            'user_id' => 15,
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
            ],
            'total_amount' => 250.00,
        ];

        $response = $this->postJson('/api/internal/events/order-created', $payload, [
            'X-Service-Secret' => 'invalid-secret',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Unauthorized');
    }

    /**
     * Test missing service secret returns 401
     */
    public function test_missing_service_secret_returns_401(): void
    {
        $payload = [
            'event_id' => 'abc-123-def-456',
            'event' => 'OrderCreated',
            'order_id' => 100,
            'user_id' => 15,
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
            ],
            'total_amount' => 250.00,
        ];

        $response = $this->postJson('/api/internal/events/order-created', $payload);

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
    }

    /**
     * Test invalid event payload returns 422
     */
    public function test_invalid_event_payload_returns_422(): void
    {
        $payload = [
            'event_id' => 'not-a-uuid',
            'event' => 'OrderCreated',
            'order_id' => 100,
            'user_id' => 15,
            'items' => [],  // Empty items array is invalid
            'total_amount' => 250.00,
        ];

        $response = $this->postJson('/api/internal/events/order-created', $payload, [
            'X-Service-Secret' => config('services.order_service.event_secret'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /**
     * Test valid event dispatches ProcessOrderCreated job
     */
    public function test_valid_event_dispatches_process_order_created_job(): void
    {
        Queue::fake();

        $payload = [
            'event_id' => 'abc-123-def-456',
            'event' => 'OrderCreated',
            'order_id' => 100,
            'user_id' => 15,
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
            ],
            'total_amount' => 250.00,
        ];

        $response = $this->postJson('/api/internal/events/order-created', $payload, [
            'X-Service-Secret' => config('services.order_service.event_secret'),
        ]);

        $response->assertStatus(202);
        Queue::assertPushed(ProcessOrderCreated::class);
    }

    /**
     * Test ProcessOrderCreated job updates product stock
     */
    public function test_process_order_created_job_updates_product_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
            'stock' => 10,
            'status' => true,
        ]);

        $job = new ProcessOrderCreated(
            event_id: 'abc-123-def-456',
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 2]],
            total_amount: 199.98,
        );

        $job->handle();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);
    }

    /**
     * Test ProcessOrderCreated job processes multiple items
     */
    public function test_process_order_created_job_processes_multiple_items(): void
    {
        $product1 = Product::create([
            'name' => 'Product 1',
            'sku' => 'PROD-001',
            'price' => 50.00,
            'stock' => 20,
            'status' => true,
        ]);

        $product2 = Product::create([
            'name' => 'Product 2',
            'sku' => 'PROD-002',
            'price' => 100.00,
            'stock' => 15,
            'status' => true,
        ]);

        $job = new ProcessOrderCreated(
            event_id: 'abc-123-def-456',
            order_id: 100,
            user_id: 15,
            items: [
                ['product_id' => $product1->id, 'quantity' => 5],
                ['product_id' => $product2->id, 'quantity' => 3],
            ],
            total_amount: 550.00,
        );

        $job->handle();

        $this->assertDatabaseHas('products', [
            'id' => $product1->id,
            'stock' => 15,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product2->id,
            'stock' => 12,
        ]);
    }

    /**
     * Test product does not allow negative stock
     */
    public function test_product_does_not_allow_negative_stock(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
            'stock' => 5,
            'status' => true,
        ]);

        $job = new ProcessOrderCreated(
            event_id: 'abc-123-def-456',
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 10]],  // More than available
            total_amount: 999.90,
        );

        $job->handle();

        // Stock should remain unchanged since we don't allow negative stock
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5,  // Unchanged
        ]);
    }

    /**
     * Test non-existent product is handled safely
     */
    public function test_non_existent_product_is_handled_safely(): void
    {
        $job = new ProcessOrderCreated(
            event_id: 'abc-123-def-456',
            order_id: 100,
            user_id: 15,
            items: [['product_id' => 9999, 'quantity' => 2]],  // Non-existent product
            total_amount: 250.00,
        );

        // Should not throw exception
        $job->handle();

        // Should record the event as processed anyway
        $this->assertDatabaseHas('processed_events', [
            'event_id' => 'abc-123-def-456',
            'event_type' => 'OrderCreated',
        ]);
    }

    /**
     * Test duplicate event does not reduce stock twice (idempotency)
     */
    public function test_duplicate_event_does_not_reduce_stock_twice(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
            'stock' => 10,
            'status' => true,
        ]);

        $eventId = 'abc-123-def-456';

        // First processing
        $job1 = new ProcessOrderCreated(
            event_id: $eventId,
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 2]],
            total_amount: 199.98,
        );
        $job1->handle();

        // Verify stock was reduced
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);

        // Second processing with same event_id (duplicate)
        $job2 = new ProcessOrderCreated(
            event_id: $eventId,
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 2]],
            total_amount: 199.98,
        );
        $job2->handle();

        // Stock should still be 8, NOT reduced again
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 8,
        ]);
    }

    /**
     * Test processed_events stores event_id
     */
    public function test_processed_events_stores_event_id(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
            'stock' => 10,
            'status' => true,
        ]);

        $eventId = 'abc-123-def-456';

        $job = new ProcessOrderCreated(
            event_id: $eventId,
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 2]],
            total_amount: 199.98,
        );

        $job->handle();

        $this->assertDatabaseHas('processed_events', [
            'event_id' => $eventId,
            'event_type' => 'OrderCreated',
            'order_id' => 100,
            'user_id' => 15,
        ]);
    }

    /**
     * Test event_id uniqueness is enforced
     */
    public function test_event_id_uniqueness_is_enforced(): void
    {
        $eventId = 'unique-event-id';

        // Create a processed event manually
        ProcessedEvent::create([
            'event_id' => $eventId,
            'event_type' => 'OrderCreated',
            'order_id' => 100,
            'user_id' => 15,
            'payload' => [],
            'processed_at' => now(),
        ]);

        // Try to create another with the same event_id
        $this->expectException(\Exception::class);

        ProcessedEvent::create([
            'event_id' => $eventId,
            'event_type' => 'OrderCreated',
            'order_id' => 101,
            'user_id' => 16,
            'payload' => [],
            'processed_at' => now(),
        ]);
    }
}
