<?php

use App\Http\Controllers\Api\Internal\EventController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $health = HealthCheckService::check('product-service');
    $statusCode = $health['success'] ? 200 : 503;
    return response()->json($health, $statusCode);
});

// Internal event endpoints (service-to-service)
Route::post('/internal/events/order-created', [EventController::class, 'orderCreated']);

Route::apiResource('v1/products', ProductController::class)->parameters([
    'products' => 'id',
]);