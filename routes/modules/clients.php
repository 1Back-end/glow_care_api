<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("clients", \App\Http\Controllers\Api\V1\ClientController::class);
Route::patch('clients/{uuid}/is_active', [\App\Http\Controllers\Api\V1\ClientController::class, 'updateStatus']);
Route::get('export_clients', [\App\Http\Controllers\Api\V1\ClientController::class, 'export']);
