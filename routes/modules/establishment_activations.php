<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("establishment_activations", \App\Http\Controllers\Api\V1\EstablishmentActivationController::class);
