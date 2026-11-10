<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Prestation;
use App\Models\User;
use App\Models\UserEstablishment;
use App\Notifications\AppointmentNotification;
use App\Notifications\AppointmentNotificationCancel;
use App\Notifications\AppointmentNotificationValidate;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @permission_category Gestion des rendez vous
 */

class AppointmentController extends Controller
{
    protected LicenseService $licenseService;
    protected UserAuthorizationService $authService;

    public function __construct(
        LicenseService $licenseService,
        UserAuthorizationService $authService
    ) {
        $this->licenseService = $licenseService;
        $this->authService = $authService;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission AppointmentController::index
     * @permission_desc Lister les rendez-vous
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = auth()->user();

        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Appointment::with(['client', 'prestation', 'creator', 'establishment', 'updater','rejector','validator'])
            ->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser) {
                $userEstablishmentUuids = UserEstablishment::where('user_id', $currentUser->id)
                    ->pluck('establishment_uuid');
                $q->whereIn('establishment_uuid', $userEstablishmentUuids);
            });

        $query->when($request->filled('date_from') && $request->filled('date_to'), function ($q) use ($request) {
            $q->whereBetween('date_rdv', [$request->input('date_from'), $request->input('date_to')]);
        });

        $query->when(!$request->filled('date_from') && $request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request) {
            $q->whereBetween('date_rdv', [$request->input('start_date'), $request->input('end_date')]);
        });

        $query->when($request->filled('date'), function ($q) use ($request) {
            $q->whereDate('date_rdv', $request->input('date'));
        });

        $query->when(
            !$request->filled('date_from') &&
            !$request->filled('start_date') &&
            !$request->filled('end_date') &&
            !$request->filled('date') &&
            !$request->filled('search') &&
            !$request->filled('prestation_uuid'),
            function ($q) {
                $startDate = now()->startOfDay();
                $endDate = now()->addDays(7)->endOfDay();
                $q->whereBetween('date_rdv', [$startDate, $endDate]);
            }
        );

        $query->when($request->filled('prestation_uuid'), function ($q) use ($request) {
            $q->where('prestation_uuid', $request->input('prestation_uuid'));
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->input('search');

            $q->where(function ($subQ) use ($search) {
                $subQ->where('code', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($clientQ) use ($search) {
                        $clientQ->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('prestation', function ($prestQ) use ($search) {
                        $prestQ->where('name', 'like', "%{$search}%");
                    });
            });
        });

        $appointments = $query->orderBy('date_rdv', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $appointments->items(),
            'current_page' => $appointments->currentPage(),
            'last_page'    => $appointments->lastPage(),
            'total'        => $appointments->total(),
        ]);
    }


    /**
     * @param Request $request
     * @param LicenseService $licenseService
     * @return JsonResponse
     *
     * @permission AppointmentController::store
     * @permission_desc Enregistrer un nouveau rendez-vous
     */
    public function store(Request $request, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $currentUser = auth()->user();
        $this->authService->denyIfSuperAdmin($currentUser);

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $validated = $request->validate([
            'title'              => 'nullable|string|max:255',
            'establishment_uuid' => 'nullable|string|exists:establishments,uuid',
            'client_uuid'        => 'required|string|exists:clients,uuid',
            'prestation_uuid'    => 'required|string|exists:prestations,uuid',
            'date_rdv'           => 'required|date',
            'notes'              => 'nullable|string',
        ]);

        $prestation = Prestation::findOrFail($validated['prestation_uuid']);

        $appointment = Appointment::create([
            'title'              => $validated['title'] ?? 'Rendez-vous',
            'establishment_uuid' => $establishmentUuid,
            'client_uuid'        => $validated['client_uuid'],
            'prestation_uuid'    => $validated['prestation_uuid'],
            'date_rdv'           => $validated['date_rdv'],
            'price'              => $prestation->price,
            'status'             => 'pending',
            'notes'              => $validated['notes'] ?? null,
            'created_by'         => $currentUser?->id,
            'updated_by'         => $currentUser?->id,
        ]);

        $appointment->load(['client:uuid,code,full_name', 'prestation', 'validator:id,nom_utilisateur','creator:id,nom_utilisateur', 'updater:id,nom_utilisateur','rejector:id,nom_utilisateur']);

        if ($appointment->establishment_uuid) {
            $userIds = UserEstablishment::where('establishment_uuid', $appointment->establishment_uuid)
                ->pluck('user_id');

            $users = User::whereIn('id', $userIds)->get();

            $formattedDate = \Carbon\Carbon::parse($appointment->date_rdv)->format('d M Y à H:i');

            $message = "Nouveau rendez-vous : {$appointment->client?->full_name} pour la prestation « {$appointment->prestation?->name} » prévue le {$formattedDate}.";

            foreach ($users as $user) {
                $user->notify(new AppointmentNotification($message, $appointment));
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Rendez-vous créé avec succès.',
            'data'    => $appointment
        ], 201);
    }

    /**
     * @param Request $request
     * @param LicenseService $licenseService
     * @param string $uuid
     * @return JsonResponse
     *
     * @permission AppointmentController::update
     * @permission_desc Mettre à jour un rendez-vous
     */
    public function update(Request $request, LicenseService $licenseService, string $uuid): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $currentUser = auth()->user();
        $this->authService->denyIfSuperAdmin($currentUser);

        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'title'              => 'nullable|string|max:255',
            'client_uuid'        => 'sometimes|required|string|exists:clients,uuid',
            'prestation_uuid'    => 'sometimes|required|string|exists:prestations,uuid',
            'date_rdv'           => 'sometimes|required|date',
            'notes'              => 'nullable|string',
        ]);

        if (isset($validated['prestation_uuid'])) {
            $prestation = Prestation::findOrFail($validated['prestation_uuid']);
            $validated['price'] = $prestation->price;
        }

        $validated['updated_by'] = $currentUser?->id;

        $appointment->update($validated);

        $appointment->load(['client:uuid,code,full_name', 'prestation','validator:id,nom_utilisateur', 'creator:id,nom_utilisateur', 'updater:id,nom_utilisateur','rejector:id,nom_utilisateur']);

        if ($appointment->establishment_uuid) {
            $userIds = UserEstablishment::where('establishment_uuid', $appointment->establishment_uuid)
                ->pluck('user_id');

            $users = User::whereIn('id', $userIds)->get();
            \Log::info('Utilisateurs notifiés : ' . $users->count());

            $formattedDate = \Carbon\Carbon::parse($appointment->date_rdv)->format('d M Y à H:i');
            $message = "Rendez-vous modifié : {$appointment->client?->full_name} pour la prestation « {$appointment->prestation?->name} » prévue le {$formattedDate}.";

            foreach ($users as $user) {
                $user->notify(new AppointmentNotification($message, $appointment));
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Rendez-vous mis à jour avec succès.',
            'data'    => $appointment
        ]);
    }

    /**
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     *
     * @permission AppointmentController::show
     * @permission_desc Afficher les détails d'un rendez-vous
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $currentUser = $request->user();

        $query = Appointment::with(['client:uuid,code,full_name', 'prestation','validator:id,nom_utilisateur', 'creator:id,nom_utilisateur', 'updater:id,nom_utilisateur','rejector:id,nom_utilisateur']);

        $query->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser) {
            $userEstablishmentUuids = UserEstablishment::where('user_id', $currentUser->id)
                ->pluck('establishment_uuid');

            $q->whereIn('establishment_uuid', $userEstablishmentUuids);
        });

        $appointment = $query->where('uuid', $uuid)->first();

        if (!$appointment) {
            return response()->json([
                'status' => false,
                'message' => 'Rendez-vous introuvable ou accès non autorisé.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $appointment
        ]);
    }

    /**
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     *
     * @permission AppointmentController::cancel
     * @permission_desc Annuler un rendez vous
     */
    public function cancel(Request $request, string $uuid, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $currentUser = auth()->user();
        $this->authService->denyIfSuperAdmin($currentUser);

        $validated = $request->validate([
            'reason_rejected' => 'required|string|max:1000',
        ]);

        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();

        $appointment->update([
            'status'          => AppointmentStatus::CANCELLED->value,
            'rejected_by'     => $currentUser?->id,
            'reason_rejected' => $validated['reason_rejected'],
            'rejected_at'     => Carbon::now(),
            'updated_by'      => $currentUser?->id,
        ]);

        $appointment->load([
            'client:uuid,code,full_name',
            'prestation',
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'rejector:id,nom_utilisateur'
        ]);

        if ($appointment->establishment_uuid) {
            $userIds = UserEstablishment::where('establishment_uuid', $appointment->establishment_uuid)
                ->pluck('user_id');

            $users = User::whereIn('id', $userIds)->get();
            \Log::info('Utilisateurs à notifier : ' . $users->count());

            $formattedDate = Carbon::parse($appointment->date_rdv)->format('d M Y à H:i');
            $message = "Rendez-vous annulé : {$appointment->client?->full_name} (Prévu le {$formattedDate}). Motif : {$validated['reason_rejected']}";

            foreach ($users as $user) {
                $user->notify(new AppointmentNotificationCancel($message, $appointment));
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Rendez-vous annulé avec succès.',
            'data'    => $appointment
        ], 200);
    }

    /**
     * @param Request $request
     * @param string $uuid
     * @return JsonResponse
     *
     * @permission AppointmentController::validateAppointment
     * @permission_desc Confirmer un rendez vous
     */
    public function validateAppointment(Request $request, string $uuid, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $currentUser = auth()->user();
        $this->authService->denyIfSuperAdmin($currentUser);

        $appointment = Appointment::where('uuid', $uuid)->firstOrFail();

        $appointment->update([
            'status'       => AppointmentStatus::COMPLETED->value,
            'validated_by' => $currentUser?->id,
            'validated_at' => Carbon::now(),
            'updated_by'   => $currentUser?->id,
        ]);

        $appointment->load([
            'client:uuid,code,full_name',
            'prestation',
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'validator:id,nom_utilisateur'
        ]);

        if ($appointment->establishment_uuid) {
            $userIds = UserEstablishment::where('establishment_uuid', $appointment->establishment_uuid)
                ->pluck('user_id');

            $users = User::whereIn('id', $userIds)->get();
            \Log::info('Utilisateurs à notifier (validation) : ' . $users->count());

            $formattedDate = Carbon::parse($appointment->date_rdv)->format('d M Y à H:i');
            $message = "Rendez-vous confirmé : {$appointment->client?->full_name} (Prévu le {$formattedDate}).";

            foreach ($users as $user) {
                $user->notify(new AppointmentNotificationValidate($message, $appointment));
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Rendez-vous validé avec succès.',
            'data'    => $appointment
        ], 200);
    }

}
