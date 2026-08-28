<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessOrderCreated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    /**
     * Handle OrderCreated event from Order Service.
     */
    public function orderCreated(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        
        // Set log context
        Log::withContext([
            'service' => 'product-service',
            'correlation_id' => $correlationId,
        ]);

        // Verify service-to-service authentication
        $serviceSecret = $request->header('X-Service-Secret');
        $expectedSecret = config('services.order_service.event_secret');

        if (!$serviceSecret || $serviceSecret !== $expectedSecret) {
            Log::warning('Unauthorized event request received');
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        // Validate the event payload
        try {
            $validated = $request->validate([
                'event_id' => 'required|uuid',
                'event' => 'required|in:OrderCreated',
                'order_id' => 'required|integer',
                'user_id' => 'required|integer',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|integer',
                'items.*.quantity' => 'required|integer|min:1',
                'total_amount' => 'required|numeric',
            ]);
        } catch (ValidationException $e) {
            Log::warning('Invalid event payload received', [
                'errors' => array_keys($e->errors()),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid payload',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            Log::info('OrderCreated event received', [
                'event_id' => $validated['event_id'],
                'order_id' => $validated['order_id'],
                'user_id' => $validated['user_id'],
                'item_count' => count($validated['items']),
            ]);

            // Dispatch the job to process the event asynchronously
            ProcessOrderCreated::dispatch(
                event_id: $validated['event_id'],
                order_id: $validated['order_id'],
                user_id: $validated['user_id'],
                items: $validated['items'],
                total_amount: (float) $validated['total_amount'],
                correlation_id: $correlationId,
            );

            Log::info('OrderCreated event queued for processing', [
                'event_id' => $validated['event_id'],
                'order_id' => $validated['order_id'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Event accepted',
            ], 202);
        } catch (\Exception $e) {
            Log::error('Failed to queue event for processing', [
                'event_id' => $validated['event_id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process event',
            ], 500);
        }
    }
}
