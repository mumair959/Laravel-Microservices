<?php

use App\Http\Controllers\GatewayController;
use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $health = HealthCheckService::check('api-gateway');
    $statusCode = $health['success'] ? 200 : 503;
    return response()->json($health, $statusCode);
});

// Auth Service forwarding - public routes
Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [GatewayController::class, 'forwardAuthRequest']);
    Route::post('/login', [GatewayController::class, 'forwardAuthRequest']);
    
    // Protected auth routes
    Route::middleware('auth.service')->group(function () {
        Route::get('/me', [GatewayController::class, 'forwardAuthRequest']);
        Route::post('/logout', [GatewayController::class, 'forwardAuthRequest']);
    });
});

// Product Service forwarding - protected routes
Route::prefix('v1/products')->middleware('auth.service')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardProductRequest']);
    Route::post('/', [GatewayController::class, 'forwardProductRequest']);
    Route::get('/{id}', [GatewayController::class, 'forwardProductRequest']);
    Route::put('/{id}', [GatewayController::class, 'forwardProductRequest']);
    Route::delete('/{id}', [GatewayController::class, 'forwardProductRequest']);
});

// Order Service forwarding - protected routes
Route::prefix('v1/orders')->middleware('auth.service')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardOrderRequest']);
    Route::post('/', [GatewayController::class, 'forwardOrderRequest']);
    Route::get('/{id}', [GatewayController::class, 'forwardOrderRequest']);
});
