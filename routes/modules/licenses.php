<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("licenses", \App\Http\Controllers\Api\V1\LicenseController::class);
Route::patch('licenses/{uuid}/is_active', [\App\Http\Controllers\Api\V1\LicenseController::class, 'updateStatus']);

