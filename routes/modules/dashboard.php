<?php
use Illuminate\Support\Facades\Route;
Route::get('/dashboard/clients-stats', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getClientStats']);
Route::get('/dashboard/owners-count', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getOwnersCount']);
Route::get('/dashboard/appointments-stats', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getAppointmentStats']);
