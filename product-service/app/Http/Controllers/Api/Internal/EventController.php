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
        // Verify service-to-service authentication
        $serviceSecret = $request->header('X-Service-Secret');
        $expectedSecret = config('services.order_service.event_secret');

        if (!$serviceSecret || $serviceSecret !== $expectedSecret) {
            Log::warning('Unauthorized event request', [
                'ip' => $request->ip(),
            ]);

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
            Log::warning('Invalid event payload', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid payload',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            // Dispatch the job to process the event asynchronously
            ProcessOrderCreated::dispatch(
                event_id: $validated['event_id'],
                order_id: $validated['order_id'],
                user_id: $validated['user_id'],
                items: $validated['items'],
                total_amount: (float) $validated['total_amount'],
            );

            Log::info('OrderCreated event accepted and queued', [
                'event_id' => $validated['event_id'],
                'order_id' => $validated['order_id'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Event accepted',
            ], 202);
        } catch (\Exception $e) {
            Log::error('Failed to queue event', [
                'event_id' => $validated['event_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process event',
            ], 500);
        }
    }
}
