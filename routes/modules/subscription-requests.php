<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("subscription-requests", \App\Http\Controllers\Api\V1\SubscriptionRequestController::class);
Route::patch('subscription-requests/{uuid}/status', [\App\Http\Controllers\Api\V1\SubscriptionRequestController::class, 'updateStatus']);
