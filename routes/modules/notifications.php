<?php
use Illuminate\Support\Facades\Route;

Route::apiResource('notifications', \App\Http\Controllers\Api\V1\NotificationController::class);
Route::patch('notifications/{id}/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAsRead']);
Route::post('notifications/mark-all-read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAllAsRead']);
