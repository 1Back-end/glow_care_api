<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("owners", \App\Http\Controllers\Api\V1\OwnerController::class);
Route::patch('owners/{uuid}/status', [\App\Http\Controllers\Api\V1\OwnerController::class, 'updateStatus']);
Route::get('owners/establishments/by-user', [\App\Http\Controllers\Api\V1\OwnerController::class, 'getByUser']);
