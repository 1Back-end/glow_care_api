<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Enums\StateFacture;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Owner;
use App\Models\Prestation;
use App\Services\LicenseService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

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

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::getInvoiceStats
     * @permission_desc Afficher le nombre total de prestations
     */
    public function getInvoiceStats(Request $request)
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $baseQuery = Invoice::where('establishment_uuid', $establishmentUuid);

        $totalInvoices = (clone $baseQuery)->count();

        $invoicesLastMonth = (clone $baseQuery)
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $growthRate = $invoicesLastMonth > 0
            ? round((($totalInvoices - $invoicesLastMonth) / $invoicesLastMonth) * 100, 1)
            : 0;

        return response()->json([
            'status' => true,
            'data' => [
                'total'  => $totalInvoices,
                'growth' => $growthRate,
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::getRevenueEvolution
     * @permission_desc Afficher l'évolution du chiffre d'affaires
     */
    public function getRevenueEvolution(Request $request)
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $period = $request->query('period', '30d');
        [$unit, $count] = match ($period) {
            '7d'    => ['day', 7],
            '3m'    => ['week', 13],
            '12m'   => ['month', 12],
            default => ['day', 30],
        };

        $shift = fn (CarbonInterface $d, int $n) => match ($unit) {
            'day'   => $d->copy()->addDays($n),
            'week'  => $d->copy()->addWeeks($n),
            default => $d->copy()->addMonths($n),
        };

        $keyOf = fn (CarbonInterface $d) => match ($unit) {
            'day'   => $d->format('Y-m-d'),
            'week'  => $d->copy()->startOfWeek()->format('Y-m-d'),
            default => $d->format('Y-m'),
        };

        $first = match ($unit) {
            'day'   => now()->startOfDay(),
            'week'  => now()->startOfWeek(),
            default => now()->startOfMonth(),
        };

        $start         = $shift($first, -($count - 1));
        $previousStart = $shift($start, -$count);

        $invoices = Invoice::where('establishment_uuid', $establishmentUuid)
            ->whereIn('regulated', [
                StateFacture::IN_PROGRESS->value,
                StateFacture::PAID->value,
            ])
            ->where('created_at', '>=', $previousStart)
            ->get();

        $current  = $invoices->filter(fn ($i) => $i->created_at->gte($start));
        $previous = $invoices->filter(fn ($i) => $i->created_at->lt($start));

        $byBucket = $current
            ->groupBy(fn ($i) => $keyOf($i->created_at))
            ->map(fn ($g) => $g->sum(fn ($i) => (float) $i->total_amount));

        $evolution  = [];
        $cumulative = 0;

        for ($i = 0; $i < $count; $i++) {
            $date    = $shift($start, $i);
            $key     = $keyOf($date);
            $revenue = (float) ($byBucket[$key] ?? 0);
            $cumulative += $revenue;

            $evolution[] = [
                'key'        => $key,
                'label'      => $date->copy()->locale('fr')->isoFormat($unit === 'month' ? 'MMM YYYY' : 'D MMM'),
                'revenue'    => $revenue,
                'cumulative' => $cumulative,
            ];
        }

        $previousTotal = $previous->sum(fn ($i) => (float) $i->total_amount);

        $growthRate = $previousTotal > 0
            ? round((($cumulative - $previousTotal) / $previousTotal) * 100, 1)
            : 0;

        return response()->json([
            'status' => true,
            'data' => [
                'period'    => $period,
                'total'     => $cumulative,
                'growth'    => $growthRate,
                'evolution' => $evolution,
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::getTopPrestations
     * @permission_desc Afficher les prestations les plus demandées
     */
    public function getTopPrestations(Request $request)
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $start = match ($request->query('period', '30d')) {
            '7d'    => now()->subDays(6)->startOfDay(),
            '3m'    => now()->subMonths(3)->startOfDay(),
            '12m'   => now()->subMonths(12)->startOfDay(),
            'all'   => null,
            default => now()->subDays(29)->startOfDay(),
        };

        $rows = InvoiceItem::query()
            ->join('invoices', 'invoices.uuid', '=', 'invoices_items.invoices_uuid')
            ->join('prestations', 'prestations.uuid', '=', 'invoices_items.prestation_uuid')
            ->where('invoices_items.establishment_uuid', $establishmentUuid)
            ->whereNull('invoices.deleted_at')
            ->whereIn('invoices.regulated', [
                StateFacture::IN_PROGRESS->value,
                StateFacture::PAID->value,
            ])
            ->when($start, fn ($q) => $q->where('invoices.created_at', '>=', $start))
            ->groupBy('prestations.uuid', 'prestations.name')
            ->selectRaw('prestations.uuid, prestations.name, SUM(invoices_items.quantity) as total')
            ->orderByDesc('total')
            ->get();

        $grandTotal = (int) $rows->sum('total');

        $percent = fn (int $value) => $grandTotal > 0
            ? round(($value / $grandTotal) * 100, 1)
            : 0;

        $items = $rows->take(4)->map(fn ($row) => [
            'uuid'    => $row->uuid,
            'name'    => $row->name,
            'total'   => (int) $row->total,
            'percent' => $percent((int) $row->total),
        ])->values();

        $othersTotal = (int) $rows->slice(4)->sum('total');

        if ($othersTotal > 0) {
            $items->push([
                'uuid'    => null,
                'name'    => 'Autres',
                'total'   => $othersTotal,
                'percent' => $percent($othersTotal),
            ]);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'total' => $grandTotal,
                'items' => $items,
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::getAverageBasketStats
     * @permission_desc Afficher les statistiques du panier moyen
     */
    public function getAverageBasketStats(Request $request)
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $paidInvoices = Invoice::with('items')
            ->where('establishment_uuid', $establishmentUuid)
            ->whereIn('regulated', [2, 3])
            ->get();

        $totalPaidInvoices = $paidInvoices->count();
        $totalRevenue = $paidInvoices->sum('total_amount');

        $averageBasket = $totalPaidInvoices > 0
            ? round($totalRevenue / $totalPaidInvoices, 0)
            : 0;

        $startOfMonth = now()->startOfMonth();
        $invoicesLastMonth = $paidInvoices->filter(function ($invoice) use ($startOfMonth) {
            return $invoice->created_at < $startOfMonth;
        });

        $countLastMonth = $invoicesLastMonth->count();
        $revenueLastMonth = $invoicesLastMonth->sum('total_amount');

        $averageBasketLastMonth = $countLastMonth > 0
            ? ($revenueLastMonth / $countLastMonth)
            : 0;

        $growthRate = $averageBasketLastMonth > 0
            ? round((($averageBasket - $averageBasketLastMonth) / $averageBasketLastMonth) * 100, 1)
            : 0;

        return response()->json([
            'status' => true,
            'data' => [
                'average_basket' => $averageBasket,
                'growth'         => $growthRate,
            ]
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::today
     * @permission_desc Afficher les rendez-vous du jour
     */
    public function today(Request $request)
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        try {
            $todayStart = Carbon::today()->startOfDay();
            $todayEnd = Carbon::today()->endOfDay();

            $appointments = Appointment::with(['client:uuid,full_name', 'prestation:uuid,name'])
                ->where('establishment_uuid', $establishmentUuid)
                ->whereBetween('date_rdv', [$todayStart, $todayEnd])
                ->orderBy('date_rdv', 'asc')
                ->take(10)
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Rendez-vous du jour récupérés avec succès.',
                'data'    => $appointments,
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de la récupération des rendez-vous.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission DashboardStatisticsController::RecentActivity
     * @permission_desc Afficher les activités récentes
     */
    public function RecentActivity(Request $request): JsonResponse
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');
        $limit = max(1, min((int) $request->get('limit', 5), 30));
        $maxAppointments = 2;

        if (!$establishmentUuid) {
            return response()->json([
                'status'  => false,
                'message' => 'En-tête X-Establishment-Uuid manquant.',
                'data'    => [],
            ], 422);
        }

        // 1. Clients récents (priorité 1)
        $clients = Client::where('establishment_uuid', $establishmentUuid)
            ->latest('created_at')
            ->take($limit)
            ->get()
            ->map(function (Client $client) {
                $createdAt = Carbon::parse($client->created_at);
                $clientName = $client->full_name ?: 'Client';

                return [
                    'uuid'       => 'client-' . $client->uuid,
                    'type'       => 'client',
                    'priority'   => 1,
                    'title'      => 'Nouveau client',
                    'subtitle'   => "Nom : {$clientName}",
                    'time_label' => $createdAt->format('H\hi'),
                    'timestamp'  => $createdAt->toIso8601String(),
                    'sort_ts'    => $createdAt->timestamp,
                    'route_uuid' => $client->uuid,
                ];
            });

        // 2. Rendez-vous récents — maximum 2 (priorité 2)
        $appointments = Appointment::with(['client:uuid,full_name', 'prestation:uuid,name'])
            ->where('establishment_uuid', $establishmentUuid)
            ->latest('created_at')
            ->take($maxAppointments)
            ->get()
            ->map(function (Appointment $appointment) {
                $createdAt = Carbon::parse($appointment->created_at);
                $clientName = $appointment->client?->full_name ?: 'Client';

                return [
                    'uuid'       => 'appointment-' . $appointment->uuid,
                    'type'       => 'appointment',
                    'priority'   => 2,
                    'title'      => $appointment->title ?: 'Nouveau rendez-vous',
                    'subtitle'   => "Client : {$clientName}",
                    'time_label' => $createdAt->format('H\hi'),
                    'timestamp'  => $createdAt->toIso8601String(),
                    'sort_ts'    => $createdAt->timestamp,
                    'route_uuid' => $appointment->uuid,
                ];
            });

        // 3. Prestations récentes (priorité 3)
        $prestations = Prestation::where('establishment_uuid', $establishmentUuid)
            ->latest('created_at')
            ->take($limit)
            ->get()
            ->map(function (Prestation $prestation) {
                $createdAt = Carbon::parse($prestation->created_at);
                $prestationName = $prestation->name ?: 'Prestation';

                return [
                    'uuid'       => 'prestation-' . $prestation->uuid,
                    'type'       => 'prestation',
                    'priority'   => 3,
                    'title'      => 'Nouvelle prestation',
                    'subtitle'   => "Prestation : {$prestationName}",
                    'time_label' => $createdAt->format('H\hi'),
                    'timestamp'  => $createdAt->toIso8601String(),
                    'sort_ts'    => $createdAt->timestamp,
                    'route_uuid' => $prestation->uuid,
                ];
            });

        $invoices = Invoice::with(['client:uuid,full_name'])
            ->where('establishment_uuid', $establishmentUuid)
            ->latest('created_at')
            ->take($limit)
            ->get()
            ->map(function (Invoice $invoice) {
                $createdAt = Carbon::parse($invoice->created_at);
                $clientName = $invoice->client?->full_name;
                $stateLabel = $invoice->regulated instanceof \BackedEnum
                    ? $invoice->regulated->value
                    : ($invoice->regulated?->name ?? null);

                $subtitle = $clientName
                    ? "Client : {$clientName}"
                    : ($invoice->code ? "Réf : {$invoice->code}" : 'Facture');

                if ($stateLabel) {
                    $subtitle .= " ({$stateLabel})";
                }

                return [
                    'uuid'       => 'invoice-' . $invoice->uuid,
                    'type'       => 'invoice',
                    'priority'   => 4,
                    'title'      => 'Facture',
                    'subtitle'   => $subtitle,
                    'time_label' => $createdAt->format('H\hi'),
                    'timestamp'  => $createdAt->toIso8601String(),
                    'sort_ts'    => $createdAt->timestamp,
                    'route_uuid' => $invoice->uuid,
                ];
            });

        $activities = $clients
            ->concat($appointments)
            ->concat($prestations)
            ->concat($invoices)
            ->sort(function ($a, $b) {
                if ($a['sort_ts'] === $b['sort_ts']) {
                    return $a['priority'] <=> $b['priority'];
                }
                return $b['sort_ts'] <=> $a['sort_ts'];
            })
            ->take($limit)
            ->map(function ($item) {
                unset($item['sort_ts'], $item['priority']);
                return $item;
            })
            ->values();

        return response()->json([
            'status' => true,
            'data'   => $activities,
        ]);
    }
}
