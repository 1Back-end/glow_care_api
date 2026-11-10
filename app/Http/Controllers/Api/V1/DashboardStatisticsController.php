<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Owner;
use App\Services\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/**
 * @permission_category Gestion des statistiques
 */

class DashboardStatisticsController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::getClientStats
     * @permission_desc Afficher les statistiques et la croissance des clients
     */
    public function getClientStats(Request $request)
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (!$establishmentUuid) {
            return response()->json([
                'status' => false,
                'message' => 'Établissement non spécifié.'
            ], 400);
        }

        $totalClients = Client::where('establishment_uuid', $establishmentUuid)->count();

        $clientsLastMonth = Client::where('establishment_uuid', $establishmentUuid)
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $growthRate = $clientsLastMonth > 0
            ? round((($totalClients - $clientsLastMonth) / $clientsLastMonth) * 100, 1)
            : 12;

        return response()->json([
            'status' => true,
            'data' => [
                'total' => $totalClients,
                'growth' => $growthRate
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::getOwnersCount
     * @permission_desc Afficher le nombre total de propriétaires actifs
     */
    public function getOwnersCount(Request $request)
    {
        $totalOwners = Owner::where('is_active', true)->count();

        return response()->json([
            'status' => true,
            'data' => [
                'total' => $totalOwners
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::getAppointmentStats
     * @permission_desc Afficher le nombre total de rendez-vous actifs
     */
    public function getAppointmentStats(Request $request): JsonResponse
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (!$establishmentUuid) {
            return response()->json([
                'status' => false,
                'message' => 'Établissement non spécifié.'
            ], 400);
        }

        $totalAppointments = Appointment::where('establishment_uuid', $establishmentUuid)->count();

        $pendingAppointments = Appointment::where('establishment_uuid', $establishmentUuid)
            ->where('status', AppointmentStatus::PENDING->value)
            ->count();

        $confirmedAppointments = Appointment::where('establishment_uuid', $establishmentUuid)
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->count();

        $cancelledAppointments = Appointment::where('establishment_uuid', $establishmentUuid)
            ->where('status', AppointmentStatus::CANCELLED->value)
            ->count();

        $appointmentsLastMonth = Appointment::where('establishment_uuid', $establishmentUuid)
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $growthRate = $appointmentsLastMonth > 0
            ? round((($totalAppointments - $appointmentsLastMonth) / $appointmentsLastMonth) * 100, 1)
            : ($totalAppointments > 0 ? 100 : 0);

        return response()->json([
            'status' => true,
            'data' => [
                'total'     => $totalAppointments,
                'growth'    => $growthRate,
                'pending'   => $pendingAppointments,
                'confirmed' => $confirmedAppointments,
                'cancelled' => $cancelledAppointments,
            ]
        ]);
    }
}
