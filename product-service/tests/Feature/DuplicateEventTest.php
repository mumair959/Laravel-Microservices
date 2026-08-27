<?php

namespace Tests\Feature;

use App\Jobs\ProcessOrderCreated;
use App\Models\ProcessedEvent;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DuplicateEventTest extends TestCase
{
    public function test_duplicate_order_created_event_does_not_deduct_stock_twice(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-SKU-001',
            'price' => 100.00,
            'stock' => 10,
            'status' => true,
        ]);

        $eventId = 'test-event-123';

        // Process the event first time
        $job = new ProcessOrderCreated(
            event_id: $eventId,
            order_id: 1,
            user_id: 1,
            items: [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
            total_amount: 200.00,
            correlation_id: 'test-correlation-123',
        );

        $job->handle();

        // Verify stock was deducted
        $product->refresh();
        $this->assertEquals(8, $product->stock);

        // Process the same event again
        $job->handle();

        // Verify stock wasn't deducted again
        $product->refresh();
        $this->assertEquals(8, $product->stock);

        // Verify only one ProcessedEvent record exists
        $processedEvents = ProcessedEvent::where('event_id', $eventId)->get();
        $this->assertCount(1, $processedEvents);
    }
}
