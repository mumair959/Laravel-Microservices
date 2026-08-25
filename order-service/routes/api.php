<?php

use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'service' => 'order-service',
        'status' => 'healthy',
    ]);
});

Route::apiResource('v1/orders', OrderController::class)->only(['index', 'store', 'show']);