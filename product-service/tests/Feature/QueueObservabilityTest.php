<?php

namespace Tests\Feature;

use App\Jobs\ProcessOrderCreated;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class QueueObservabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test queue job logs with correlation ID
     */
    public function test_queue_job_logs_with_correlation_id(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
            'stock' => 10,
            'status' => true,
        ]);

        $correlationId = 'test-correlation-' . uniqid();

        Log::shouldReceive('withContext')
            ->with(\Mockery::on(function ($context) use ($correlationId) {
                return isset($context['correlation_id']) &&
                       $context['correlation_id'] === $correlationId &&
                       isset($context['job']) &&
                       isset($context['event_id']);
            }))
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->atLeast()->once()
            ->andReturnNull();

        $job = new ProcessOrderCreated(
            event_id: 'abc-123-def-456',
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 2]],
            total_amount: 199.98,
            correlation_id: $correlationId,
        );

        $job->handle();
    }

    /**
     * Test queue job failed logs critical information
     */
    public function test_queue_job_failed_logs_critical_information(): void
    {
        $correlationId = 'test-correlation-' . uniqid();

        Log::shouldReceive('critical')
            ->once()
            ->with('ProcessOrderCreated job failed permanently', \Mockery::subset([
                'service' => 'product-service',
                'job' => 'ProcessOrderCreated',
                'event_id' => 'abc-123',
                'correlation_id' => $correlationId,
            ]))
            ->andReturnNull();

        $job = new ProcessOrderCreated(
            event_id: 'abc-123',
            order_id: 100,
            user_id: 15,
            items: [['product_id' => 1, 'quantity' => 1]],
            total_amount: 100.00,
            correlation_id: $correlationId,
        );

        $exception = new \Exception('Test error');
        $job->failed($exception);
    }

    /**
     * Test queue job logs processing steps
     */
    public function test_queue_job_logs_processing_steps(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
            'stock' => 10,
            'status' => true,
        ]);

        Log::shouldReceive('withContext')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->with('Processing OrderCreated event started')
            ->once()
            ->andReturnNull();

        Log::shouldReceive('info')
            ->with('Processing order item', \Mockery::subset([
                'product_id' => $product->id,
                'quantity' => 2,
            ]))
            ->once()
            ->andReturnNull();

        Log::shouldReceive('info')
            ->with('Product stock deducted successfully', \Mockery::subset([
                'product_id' => $product->id,
                'quantity_deducted' => 2,
                'new_stock' => 8,
            ]))
            ->once()
            ->andReturnNull();

        Log::shouldReceive('info')
            ->with('OrderCreated event processing completed successfully')
            ->once()
            ->andReturnNull();

        $job = new ProcessOrderCreated(
            event_id: 'abc-123-def-456',
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 2]],
            total_amount: 199.98,
            correlation_id: 'test-correlation',
        );

        $job->handle();
    }

    /**
     * Test duplicate event is logged
     */
    public function test_duplicate_event_detection_is_logged(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
            'stock' => 10,
            'status' => true,
        ]);

        // First job execution
        $job1 = new ProcessOrderCreated(
            event_id: 'abc-123-def-456',
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 2]],
            total_amount: 199.98,
        );
        $job1->handle();

        // Second execution with same event ID
        Log::shouldReceive('withContext')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->with('Processing OrderCreated event started')
            ->once()
            ->andReturnNull();

        Log::shouldReceive('info')
            ->with('Duplicate event detected and skipped')
            ->once()
            ->andReturnNull();

        $job2 = new ProcessOrderCreated(
            event_id: 'abc-123-def-456',  // Same event ID
            order_id: 100,
            user_id: 15,
            items: [['product_id' => $product->id, 'quantity' => 2]],
            total_amount: 199.98,
        );
        $job2->handle();
    }
}
