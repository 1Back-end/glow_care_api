<?php

use Illuminate\Support\Facades\Route;
Route::apiResource('countries', \App\Http\Controllers\CountryController::class);
