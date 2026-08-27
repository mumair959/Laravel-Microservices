<?php

use App\Http\Controllers\Api\V1\OrderController;
use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $health = HealthCheckService::check('order-service');
    $statusCode = $health['success'] ? 200 : 503;
    return response()->json($health, $statusCode);
});

Route::apiResource('v1/orders', OrderController::class)->only(['index', 'store', 'show']);