<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('regulation_methods', \App\Http\Controllers\Api\V1\RegulationMethodController::class);
Route::patch('regulation_methods/{regulationMethod}/activate', [\App\Http\Controllers\Api\V1\RegulationMethodController::class, 'activate']);
