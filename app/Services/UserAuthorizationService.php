<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

class UserAuthorizationService
{
    /**
     * Empêche une action si l'utilisateur est un super admin.
     *
     * @param User $user
     * @param string $message
     * @return void
     *
     * @throws HttpResponseException
     */
    public function denyIfSuperAdmin(
        User $user,
        string $message = 'Les actions de modification ou de suppression sont interdites sur ce compte.'
    ): void {
        if ($user->is_super_admin) {
            throw new HttpResponseException(
                response()->json([
                    'status' => false,
                    'message' => $message,
                ], 403)
            );
        }
    }
}
