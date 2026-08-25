<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Jobs\PublishOrderCreated;
use App\Models\Order;
use App\Services\ProductServiceClient;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly ProductServiceClient $productService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        // Get authenticated user ID from trusted Gateway header
        $userId = $request->header('X-User-Id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => Order::with('items')
                ->where('user_id', $userId)
                ->latest()
                ->paginate(),
        ]);
    }

    public function store(StoreOrderRequest $request, DatabaseManager $database): JsonResponse
    {
        // Get authenticated user ID from trusted Gateway header
        $userId = $request->header('X-User-Id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $items = [];
        $orderTotal = 0.0;

        foreach ($request->validated('items') as $requestedItem) {
            try {
                $productResponse = $this->productService->getProduct($requestedItem['product_id']);
            } catch (ConnectionException) {
                return $this->serviceUnavailableResponse();
            }

            if ($productResponse->serverError() || $productResponse->clientError() && !$productResponse->notFound()) {
                return $this->serviceUnavailableResponse();
            }

            if ($productResponse->notFound()) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Product not found.',
                ], 404);
            }

            $product = $productResponse->json('data');
            $quantity = $requestedItem['quantity'];
            $stock = (int) ($product['stock'] ?? 0);

            if ($quantity > $stock) {
                return response()->json([
                    'success' => false,
                    'data' => null,
                    'message' => 'Insufficient stock.',
                ], 422);
            }

            $unitPrice = (float) $product['price'];
            $itemTotal = round($unitPrice * $quantity, 2);
            $orderTotal += $itemTotal;
            $items[] = [
                'product_id' => $requestedItem['product_id'],
                'product_name' => $product['name'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $itemTotal,
            ];
        }

        $order = $database->transaction(function () use ($items, $orderTotal, $userId): Order {
            $order = Order::create([
                'user_id' => $userId,
                'status' => 'pending',
                'total_amount' => round($orderTotal, 2),
            ]);
            $order->items()->createMany($items);

            return $order->load('items');
        });

        // Dispatch the OrderCreated event only after successful order creation
        // Convert order items to the format expected by the event
        $eventItems = $order->items->map(function ($item) {
            return [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ];
        })->toArray();

        PublishOrderCreated::dispatch(
            event_id: \Ramsey\Uuid\Uuid::uuid4()->toString(),
            order_id: $order->id,
            user_id: $order->user_id,
            items: $eventItems,
            total_amount: (float) $order->total_amount,
        );

        return response()->json([
            'success' => true,
            'data' => $order,
        ], 201);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        // Get authenticated user ID from trusted Gateway header
        $userId = $request->header('X-User-Id');

        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $order = Order::with('items')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if ($order === null) {
            return response()->json([
                'success' => false,
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    private function serviceUnavailableResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Product Service is unavailable.',
        ], 503);
    }
}
