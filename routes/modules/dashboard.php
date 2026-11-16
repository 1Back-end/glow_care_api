<?php
use Illuminate\Support\Facades\Route;
Route::get('/dashboard/clients-stats', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getClientStats']);
Route::get('/dashboard/owners-count', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getOwnersCount']);
Route::get('/dashboard/appointments-stats', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getAppointmentStats']);
Route::get('/dashboard/invoices-stats', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getInvoiceStats']);
Route::get('/dashboard/invoices-variation', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getRevenueEvolution']);
Route::get('/dashboard/top-prestations', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getTopPrestations']);
Route::get('/dashboard/average-basket-stats', [\App\Http\Controllers\Api\V1\DashboardStatisticsController::class, 'getAverageBasketStats']);
