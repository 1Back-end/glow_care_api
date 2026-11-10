<?php
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Authorization\PermissionController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::middleware(['activity'])->group(function () {

    require __DIR__ . '/auth.php';
    require __DIR__ . '/authorization.php';
    require __DIR__ . '/admin.php';

    Route::middleware(['auth:sanctum', 'user.change_password', 'check.permission'])->group(function () {
        Route::get('/permissions_by_category', [PermissionController::class, 'permissionsByCategory']);

        require __DIR__ . '/modules/countries.php';
        require __DIR__ . '/modules/enums.php';
        require __DIR__ . '/modules/clients.php';
        require __DIR__ . '/modules/loyalty-rules.php';
        require __DIR__ . '/modules/establishments.php';
        require __DIR__ . '/modules/owners.php';
        require __DIR__ . '/modules/licenses.php';
        require __DIR__ . '/modules/establishment_activations.php';
        require __DIR__ . '/modules/dashboard.php';
        require __DIR__ . '/modules/subscription-requests.php';
        require __DIR__ . '/modules/notifications.php';
        require __DIR__ . '/modules/prestations.php';
        require __DIR__ . '/modules/appointments.php';
        require __DIR__ . '/modules/invoices.php';
        require __DIR__ . '/modules/regulation_methods.php';
    });
});
