<?php

use App\Http\Controllers\EnumController;
use Illuminate\Support\Facades\Route;
Route::get('enums/LoyaltyRewardType', [\App\Http\Controllers\EnumController::class, 'LoyaltyRewardType']);
Route::get('enums/PrestationCategory', [\App\Http\Controllers\Api\V1\EnumController::class, 'PrestationCategory']);
Route::get('enums/AppointmentOriginEnum', [\App\Http\Controllers\Api\V1\EnumController::class, 'AppointmentOriginEnum']);


