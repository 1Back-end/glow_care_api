<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivationStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\EstablishmentActivation;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Models\UserEstablishment;
use App\Notifications\NewSubscriptionRequestNotification;
use App\Notifications\SubscriptionRequestStatusUpdatedNotification;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @permission_category Gestion des demandes d'abonnements
 */

class SubscriptionRequestController extends Controller
{
    protected UserAuthorizationService $authService;

    public function __construct(
        UserAuthorizationService $authService
    ) {
        $this->authService = $authService;
    }
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission SubscriptionRequestController::index
     * @permission_desc Afficher la liste des demandes d'abonnements
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $currentUser = auth()->user();

        $query = SubscriptionRequest::with(['establishment', 'license', 'creator', 'validator', 'rejector', 'canceller', 'editor'])
            ->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser) {
                $userEstablishmentUuids = UserEstablishment::where('user_id', $currentUser->id)
                    ->pluck('establishment_uuid');
                $q->whereIn('establishment_uuid', $userEstablishmentUuids);
            })
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            });

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('establishment', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('license', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $subscriptionRequests = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $subscriptionRequests->items(),
            'current_page' => $subscriptionRequests->currentPage(),
            'last_page'    => $subscriptionRequests->lastPage(),
            'total'        => $subscriptionRequests->total(),
        ]);
    }
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission SubscriptionRequestController::store
     * @permission_desc Effectuer une demande d'abonnement
     */
    public function store(Request $request)
    {

        $this->authService->denyIfSuperAdmin(auth()->user());

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $validator = Validator::make([
            'establishment_uuid' => $establishmentUuid,
            'license_uuid' => $request->input('license_uuid'),
            'notes' => $request->input('notes'),
        ], [
            'establishment_uuid' => 'required|uuid|exists:establishments,uuid',
            'license_uuid' => 'required|uuid|exists:licenses,uuid',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $subscriptionRequest = SubscriptionRequest::create([
            'establishment_uuid' => $establishmentUuid,
            'license_uuid' => $request->input('license_uuid'),
            'notes' => $request->input('notes'),
            'created_by' => auth()->id(),
            'status' => \App\Enums\SubscriptionStatus::PENDING->value,
            'is_active' => true,
        ]);
        $admins = User::where('is_super_admin', true)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewSubscriptionRequestNotification($subscriptionRequest));
        } else {
            auth()->user()->notify(new NewSubscriptionRequestNotification($subscriptionRequest));
        }

        return response()->json([
            'success' => true,
            'message' => 'Demande d\'abonnement créée avec succès.',
            'data' => $subscriptionRequest->load(['establishment', 'license'])
        ], 201);
    }


    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission SubscriptionRequestController::update
     * @permission_desc Modifier une demande d'abonnement
     */
    public function update(Request $request, $uuid)
    {
        $this->authService->denyIfSuperAdmin(auth()->user());

        $subscriptionRequest = SubscriptionRequest::where('uuid', $uuid)->firstOrFail();

        if ($subscriptionRequest->status !== \App\Enums\SubscriptionStatus::PENDING->value) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une demande qui n\'est plus en attente.'
            ], 403);
        }

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $validator = Validator::make([
            'establishment_uuid' => $establishmentUuid,
            'license_uuid' => $request->input('license_uuid'),
            'notes' => $request->input('notes'),
        ], [
            'establishment_uuid' => 'required|uuid|exists:establishments,uuid',
            'license_uuid' => 'required|uuid|exists:licenses,uuid',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $subscriptionRequest->update([
            'establishment_uuid' => $establishmentUuid,
            'license_uuid' => $request->input('license_uuid'),
            'notes' => $request->input('notes'),
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Demande d\'abonnement mise à jour avec succès.',
            'data' => $subscriptionRequest->fresh(['establishment', 'license'])
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission SubscriptionRequestController::updateStatus
     * @permission_desc Changer le statut d'une demande d'abonnement
     */
    public function updateStatus(Request $request, $uuid)
    {
        $subscriptionRequest = SubscriptionRequest::with(['establishment', 'license'])->where('uuid', $uuid)->firstOrFail();

        $newStatus = $request->input('status');

        $validationRules = [
            'status' => ['required', Rule::enum(SubscriptionStatus::class)],
        ];

        if ($newStatus === SubscriptionStatus::VALIDATED->value) {
            $validationRules['password'] = ['required', function ($attribute, $value, $fail) {
                if (!Hash::check($value, auth()->user()->password)) {
                    $fail('Le mot de passe saisi est incorrect.');
                }
            }];
        } elseif ($newStatus === SubscriptionStatus::REJECTED->value || $newStatus === 'rejected' ||
            $newStatus === SubscriptionStatus::CANCELLED->value || $newStatus === 'cancelled') {
            $validationRules['reason'] = ['required', 'string', 'min:3', 'max:500'];
        }

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $reason = $request->input('reason');
        $userId = auth()->id();

        $data = [
            'status' => $newStatus,
        ];

        switch ($newStatus) {
            case SubscriptionStatus::VALIDATED->value:
                $data['validated_by'] = $userId;
                $data['reason_for_rejected'] = null;
                $data['reason_for_cancelled'] = null;
                $data['updated_by'] = $userId;
                break;

            case SubscriptionStatus::REJECTED->value:
            case 'rejected':
                $data['rejected_by'] = $userId;
                $data['reason_for_rejected'] = $reason;
                $data['updated_by'] = $userId;
                break;

            case SubscriptionStatus::CANCELLED->value:
            case 'cancelled':
                $data['cancelled_by'] = $userId;
                $data['reason_for_cancelled'] = $reason;
                $data['updated_by'] = $userId;
                break;

            default:
                $data['updated_by'] = $userId;
                break;
        }

        $subscriptionRequest->update($data);

        if ($newStatus === SubscriptionStatus::VALIDATED->value) {
            $license = $subscriptionRequest->license;
            $establishment = $subscriptionRequest->establishment;

            if ($license && $establishment) {
                EstablishmentActivation::where('establishment_uuid', $establishment->uuid)->delete();
                $now = Carbon::now();

                $expiresAt = match ($license->duration_unit) {
                    'days'   => (clone $now)->addDays($license->duration_value),
                    'weeks'  => (clone $now)->addWeeks($license->duration_value),
                    'months' => (clone $now)->addMonths($license->duration_value),
                    'years'  => (clone $now)->addYears($license->duration_value),
                    default  => (clone $now)->addDays($license->duration_value),
                };

                $plainOtp = random_int(10000000, 99999999);
                EstablishmentActivation::create([
                    'establishment_uuid' => $establishment->uuid,
                    'license_uuid'       => $license->uuid,
                    'otp'                => Hash::make($plainOtp),
                    'starts_at'          => $now,
                    'expires_at'         => $expiresAt,
                    'otp_expired_at'     => now()->addHours(24),
                    'is_active'          => false,
                    'status'             => ActivationStatus::PENDING->value,
                    'created_by'         => $userId,
                    'updated_by'         => $userId,
                ]);
            }
        }

        $notification = new SubscriptionRequestStatusUpdatedNotification($subscriptionRequest, $newStatus, $reason);

        if ($subscriptionRequest->creator) {
            $subscriptionRequest->creator->notify($notification);
        } else {
            auth()->user()->notify($notification);
        }

        return response()->json([
            'success' => true,
            'message' => 'Statut de la demande mis à jour avec succès.',
            'data' => $subscriptionRequest->fresh(['establishment', 'license', 'validator', 'rejector', 'canceller'])
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission SubscriptionRequestController::show
     * @permission_desc Afficher les détails d'une demande d'abonnement
     */
    public function show($uuid)
    {
        $subscriptionRequest = SubscriptionRequest::with([
            'establishment',
            'license',
            'creator',
            'validator',
            'rejector',
            'canceller'
        ])->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $subscriptionRequest
        ]);
    }
}
