<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public Authentication Routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Webhook route — no auth required
Route::post('/webhooks/payment', [PaymentWebhookController::class, 'handle']);

// Protected Merchant-Authenticated Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::patch('/products/{product}/stock', [ProductController::class, 'adjustStock']);
    
    Route::post('/logout', [AuthController::class, 'logout']);
});