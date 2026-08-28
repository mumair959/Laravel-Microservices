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
        $correlationId = $this->correlation_id ?: \Ramsey\Uuid\Uuid::uuid4()->toString();
        
        // Set log context for this job
        Log::withContext([
            'service' => 'product-service',
            'correlation_id' => $correlationId,
            'job' => 'ProcessOrderCreated',
            'event_id' => $this->event_id,
            'order_id' => $this->order_id,
        ]);

        Log::info('Processing OrderCreated event started');

        // Check if this event has already been processed
        $processed = ProcessedEvent::where('event_id', $this->event_id)->exists();

        if ($processed) {
            Log::info('Duplicate event detected and skipped');
            return;
        }

        // Use transaction to ensure atomicity
        DB::transaction(function (): void {
            foreach ($this->items as $item) {
                Log::info('Processing order item', [
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);

                $product = Product::lockForUpdate()->find($item['product_id']);

                if (!$product) {
                    Log::warning('Product not found in inventory', [
                        'product_id' => $item['product_id'],
                    ]);
                    continue;
                }

                $newStock = $product->stock - $item['quantity'];

                if ($newStock < 0) {
                    Log::warning('Insufficient stock - cannot fulfill order item', [
                        'product_id' => $product->id,
                        'current_stock' => $product->stock,
                        'requested_quantity' => $item['quantity'],
                    ]);
                    // Do not allow negative stock, but don't fail the job
                    // Log the event for manual review
                    continue;
                }

                $product->update(['stock' => $newStock]);

                Log::info('Product stock deducted successfully', [
                    'product_id' => $product->id,
                    'quantity_deducted' => $item['quantity'],
                    'new_stock' => $newStock,
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

            Log::info('OrderCreated event processing completed successfully');
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $correlationId = $this->correlation_id ?: 'unknown';
        
        Log::critical('ProcessOrderCreated job failed permanently', [
            'service' => 'product-service',
            'job' => 'ProcessOrderCreated',
            'event_id' => $this->event_id,
            'order_id' => $this->order_id,
            'correlation_id' => $correlationId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
