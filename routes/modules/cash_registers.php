<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("cash_registers", \App\Http\Controllers\Api\V1\CashRegisterController::class);
Route::patch('cash_registers/{uuid}/is_active', [\App\Http\Controllers\Api\V1\CashRegisterController::class, 'updateStatus']);
Route::get('cash_registers_by_user', [\App\Http\Controllers\API\V1\CashRegisterController::class, 'getByUserAndEstablishment']);
