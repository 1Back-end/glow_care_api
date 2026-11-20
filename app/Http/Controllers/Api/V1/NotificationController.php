<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Establishment;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/**
 * @permission_category Gestion des notifications
 */

class NotificationController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission NotificationController::index
     * @permission_desc Afficher la liste des notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->is_super_admin) {
            $notifications = \Illuminate\Notifications\DatabaseNotification::latest()->paginate(100);
        } else {
            $notifications = $user->notifications()->paginate(100);
        }

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission NotificationController::markAsRead
     * @permission_desc Marquer une notification spécifique comme lue
     */
    public function markAsRead($id)
    {
        $user = auth()->user();

        $notification = $user->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification introuvable.'
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marquée comme lue.',
            'data' => $notification
        ]);
    }


    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission NotificationController::markAllAsRead
     * @permission_desc Marquer toutes les notifications de l'utilisateur comme lues.
     */
    public function markAllAsRead()
    {
        auth()->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications ont été marquées comme lues.'
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission NotificationController::destroy
     * @permission_desc Supprimer une notification spécifique.
     */
    public function destroy($id)
    {
        $user = auth()->user();

        $notification = $user->notifications()->where('id', $id)->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification introuvable.'
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification supprimée avec succès.'
        ]);
    }
}
