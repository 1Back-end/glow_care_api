<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("loyalty-rules", \App\Http\Controllers\Api\V1\LoyaltyRuleController::class);
Route::patch('loyalty-rules/{uuid}/is_active', [\App\Http\Controllers\Api\V1\LoyaltyRuleController::class, 'updateStatus']);

