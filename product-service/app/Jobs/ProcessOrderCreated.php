<?php

namespace App\Jobs;

use App\Models\ProcessedEvent;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOrderCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying a job that encountered an exception.
     */
    public int $backoff = 30;

    /**
     * The timeout in seconds.
     */
    public int $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $event_id,
        public readonly int $order_id,
        public readonly int $user_id,
        public readonly array $items,
        public readonly float $total_amount,
        public readonly string $correlation_id = '',
    ) {
        $this->onQueue(config('queue.product_queue', 'product-processing'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if this event has already been processed
        $processed = ProcessedEvent::where('event_id', $this->event_id)->exists();

        if ($processed) {
            Log::info('OrderCreated event already processed, skipping', [
                'event_id' => $this->event_id,
                'order_id' => $this->order_id,
                'correlation_id' => $this->correlation_id,
            ]);
            return;
        }

        // Use transaction to ensure atomicity
        DB::transaction(function (): void {
            foreach ($this->items as $item) {
                $product = Product::lockForUpdate()->find($item['product_id']);

                if (!$product) {
                    Log::warning('Product not found for event processing', [
                        'event_id' => $this->event_id,
                        'product_id' => $item['product_id'],
                        'correlation_id' => $this->correlation_id,
                    ]);
                    continue;
                }

                $newStock = $product->stock - $item['quantity'];

                if ($newStock < 0) {
                    Log::warning('Insufficient stock for product', [
                        'event_id' => $this->event_id,
                        'product_id' => $product->id,
                        'current_stock' => $product->stock,
                        'requested_quantity' => $item['quantity'],
                        'correlation_id' => $this->correlation_id,
                    ]);
                    // Do not allow negative stock, but don't fail the job
                    // Log the event for manual review
                    continue;
                }

                $product->update(['stock' => $newStock]);

                Log::info('Product stock updated', [
                    'event_id' => $this->event_id,
                    'product_id' => $product->id,
                    'quantity_deducted' => $item['quantity'],
                    'new_stock' => $newStock,
                    'correlation_id' => $this->correlation_id,
                ]);
            }

            // Record that this event has been processed
            ProcessedEvent::create([
                'event_id' => $this->event_id,
                'event_type' => 'OrderCreated',
                'order_id' => $this->order_id,
                'user_id' => $this->user_id,
                'payload' => [
                    'items' => $this->items,
                    'total_amount' => $this->total_amount,
                ],
                'processed_at' => now(),
            ]);

            Log::info('OrderCreated event processed successfully', [
                'event_id' => $this->event_id,
                'order_id' => $this->order_id,
                'correlation_id' => $this->correlation_id,
            ]);
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessOrderCreated job failed', [
            'event_id' => $this->event_id,
            'order_id' => $this->order_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
