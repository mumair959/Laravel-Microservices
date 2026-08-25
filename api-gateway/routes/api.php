<?php

use App\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [GatewayController::class, 'health']);

// Product Service forwarding
Route::prefix('v1/products')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardProductRequest']);
    Route::post('/', [GatewayController::class, 'forwardProductRequest']);
    Route::get('/{id}', [GatewayController::class, 'forwardProductRequest']);
    Route::put('/{id}', [GatewayController::class, 'forwardProductRequest']);
    Route::delete('/{id}', [GatewayController::class, 'forwardProductRequest']);
});

// Order Service forwarding
Route::prefix('v1/orders')->group(function () {
    Route::get('/', [GatewayController::class, 'forwardOrderRequest']);
    Route::post('/', [GatewayController::class, 'forwardOrderRequest']);
    Route::get('/{id}', [GatewayController::class, 'forwardOrderRequest']);
});

// Auth Service forwarding
Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [GatewayController::class, 'forwardAuthRequest']);
    Route::post('/login', [GatewayController::class, 'forwardAuthRequest']);
    Route::get('/me', [GatewayController::class, 'forwardAuthRequest']);
    Route::post('/logout', [GatewayController::class, 'forwardAuthRequest']);
});
