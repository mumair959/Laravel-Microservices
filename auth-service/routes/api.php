<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Services\HealthCheckService;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', function () {
    $health = HealthCheckService::check('auth-service');
    $statusCode = $health['success'] ? 200 : 503;
    return response()->json($health, $statusCode);
});

Route::prefix('v1/auth')->group(function () {
    // Public routes
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Protected routes
    Route::middleware('auth.bearer')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
