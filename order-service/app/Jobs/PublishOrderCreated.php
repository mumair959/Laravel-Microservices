<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PublishOrderCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying a job that encountered an exception.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $event_id,
        public readonly int $order_id,
        public readonly int $user_id,
        public readonly array $items,
        public readonly float $total_amount,
    ) {
        $this->onQueue(config('queue.order_queue', 'order-processing'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $payload = [
            'event_id' => $this->event_id,
            'event' => 'OrderCreated',
            'order_id' => $this->order_id,
            'user_id' => $this->user_id,
            'items' => $this->items,
            'total_amount' => $this->total_amount,
        ];

        $secret = config('services.product_service.event_secret');
        $productServiceUrl = rtrim(config('services.product_service.url'), '/');

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Service-Secret' => $secret,
                ])
                ->post("{$productServiceUrl}/api/internal/events/order-created", $payload);

            if ($response->successful()) {
                Log::info('OrderCreated event published successfully', [
                    'event_id' => $this->event_id,
                    'order_id' => $this->order_id,
                ]);
            } else {
                Log::warning('Failed to publish OrderCreated event', [
                    'event_id' => $this->event_id,
                    'order_id' => $this->order_id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                throw new \Exception('Failed to publish event: HTTP ' . $response->status());
            }
        } catch (\Exception $e) {
            Log::error('Error publishing OrderCreated event', [
                'event_id' => $this->event_id,
                'order_id' => $this->order_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('PublishOrderCreated job failed permanently', [
            'event_id' => $this->event_id,
            'order_id' => $this->order_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
