<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("prestations", \App\Http\Controllers\Api\V1\PrestationController::class);
Route::patch('prestations/{uuid}/is_active', [\App\Http\Controllers\Api\V1\PrestationController::class, 'updateStatus']);

