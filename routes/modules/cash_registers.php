<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("cash_registers", \App\Http\Controllers\Api\V1\CashRegisterController::class);
Route::patch('cash_registers/{uuid}/is_active', [\App\Http\Controllers\Api\V1\CashRegisterController::class, 'updateStatus']);
Route::get('cash_registers_by_user', [\App\Http\Controllers\Api\V1\CashRegisterController::class, 'getByUserAndEstablishment']);
Route::post('cash_registers/{uuid}/open', [\App\Http\Controllers\Api\V1\CashRegisterController::class, 'open']);
Route::post('cash_registers/{uuid}/pause', [\App\Http\Controllers\Api\V1\CashRegisterController::class, 'pause']);
Route::post('cash_registers/{uuid}/resume', [\App\Http\Controllers\Api\V1\CashRegisterController::class, 'resume']);
Route::post('cash_registers/{uuid}/close', [\App\Http\Controllers\Api\V1\CashRegisterController::class, 'close']);
