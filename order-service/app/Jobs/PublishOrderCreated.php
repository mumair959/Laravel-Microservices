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
        $this->onQueue(config('queue.order_queue', 'order-processing'));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $correlationId = $this->correlation_id ?: \Ramsey\Uuid\Uuid::uuid4()->toString();
        
        // Set log context for this job
        Log::withContext([
            'service' => 'order-service',
            'correlation_id' => $correlationId,
            'job' => 'PublishOrderCreated',
            'event_id' => $this->event_id,
            'order_id' => $this->order_id,
        ]);

        Log::info('Publishing OrderCreated event started');

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
            Log::info('Sending event to Product Service');
            $startTime = microtime(true);

            $response = Http::timeout(config('services.http_timeout', 10))
                ->connectTimeout(config('services.http_connect_timeout', 5))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Service-Secret' => $secret,
                    'X-Correlation-ID' => $correlationId,
                ])
                ->post("{$productServiceUrl}/api/internal/events/order-created", $payload);

            $duration = (microtime(true) - $startTime) * 1000;

            if ($response->successful()) {
                Log::info('OrderCreated event delivered successfully', [
                    'status' => $response->status(),
                    'duration_ms' => round($duration, 2),
                    'target_service' => 'product-service',
                ]);
            } else {
                Log::warning('Failed to deliver OrderCreated event', [
                    'status' => $response->status(),
                    'duration_ms' => round($duration, 2),
                    'target_service' => 'product-service',
                ]);

                throw new \Exception('Failed to publish event: HTTP ' . $response->status());
            }
        } catch (\Exception $e) {
            Log::error('Exception while publishing OrderCreated event', [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $correlationId = $this->correlation_id ?: 'unknown';
        
        Log::critical('PublishOrderCreated job failed permanently', [
            'service' => 'order-service',
            'job' => 'PublishOrderCreated',
            'event_id' => $this->event_id,
            'order_id' => $this->order_id,
            'correlation_id' => $correlationId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
