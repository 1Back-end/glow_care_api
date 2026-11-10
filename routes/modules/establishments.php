<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("establishments", \App\Http\Controllers\Api\V1\EstablishmentController::class);
Route::patch('establishments/{uuid}/is_active', [\App\Http\Controllers\Api\V1\EstablishmentController::class, 'updateStatus']);

