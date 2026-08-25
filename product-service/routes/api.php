<?php

use App\Http\Controllers\Api\Internal\EventController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'service' => 'product-service',
        'status' => 'healthy',
    ]);
});

// Internal event endpoints (service-to-service)
Route::post('/internal/events/order-created', [EventController::class, 'orderCreated']);

Route::apiResource('v1/products', ProductController::class)->parameters([
    'products' => 'id',
]);