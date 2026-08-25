<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
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

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Order::with('items')->latest()->paginate(),
        ]);
    }

    public function store(StoreOrderRequest $request, DatabaseManager $database): JsonResponse
    {
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

        $order = $database->transaction(function () use ($items, $orderTotal): Order {
            $order = Order::create([
                'status' => 'pending',
                'total_amount' => round($orderTotal, 2),
            ]);
            $order->items()->createMany($items);

            return $order->load('items');
        });

        return response()->json([
            'success' => true,
            'data' => $order,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::with('items')->find($id);

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