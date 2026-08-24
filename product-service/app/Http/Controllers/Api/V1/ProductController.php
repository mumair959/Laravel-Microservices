<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->boolean('status')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $product,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::find($id);

        if ($product === null) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product = Product::find($id);

        if ($product === null) {
            return $this->notFoundResponse();
        }

        $product->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $product->fresh(),
        ]);
    }

    public function destroy(int $id): \Illuminate\Http\Response|JsonResponse
    {
        $product = Product::find($id);

        if ($product === null) {
            return $this->notFoundResponse();
        }

        $product->delete();

        return response()->noContent();
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
        ], 404);
    }
}