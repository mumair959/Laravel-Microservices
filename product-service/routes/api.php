<?php

use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'service' => 'product-service',
        'status' => 'healthy',
    ]);
});

Route::apiResource('v1/products', ProductController::class)->parameters([
    'products' => 'id',
]);