<?php
use Illuminate\Support\Facades\Route;
Route::apiResource("invoices", \App\Http\Controllers\Api\V1\InvoiceController::class);
Route::put('invoices/{uuid}/cancel', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'cancel']);
Route::post('invoices/{uuid}/initiate-payment', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'initiatePayment']);
