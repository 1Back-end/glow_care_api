<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("appointments", \App\Http\Controllers\Api\V1\AppointmentController::class);
Route::post('/appointments/{uuid}/cancel', [\App\Http\Controllers\Api\V1\AppointmentController::class, 'cancel']);
Route::patch('/appointments/{uuid}/validate', [\App\Http\Controllers\Api\V1\AppointmentController::class, 'validateAppointment']);
