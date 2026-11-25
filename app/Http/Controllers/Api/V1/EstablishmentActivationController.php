<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivationStatus;
use App\Models\Establishment;
use App\Models\EstablishmentActivation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;
/**
 * @permission_category Activation des abonnements
 */
class EstablishmentActivationController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission EstablishmentActivationController::store
     * @permission_desc Activer l'abonnement de mes établissements
     */
    public function store(Request $request)
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (empty($establishmentUuid)) {
            return response()->json([
                'status'  => false,
                'message' => 'L’en-tête X-Establishment-Uuid est manquant.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $validated = $request->validate([
            'otp' => 'required|digits:8',
        ]);

        try {
            return DB::transaction(function () use ($establishmentUuid, $validated) {
                EstablishmentActivation::where('establishment_uuid', $establishmentUuid)
                    ->where('is_used', false)
                    ->where('status', ActivationStatus::PENDING)
                    ->where('otp_expired_at', '<', now())
                    ->delete();

                $pendingActivations = EstablishmentActivation::where('establishment_uuid', $establishmentUuid)
                    ->where('is_used', false)
                    ->where('status', ActivationStatus::PENDING)
                    ->with('license')
                    ->get();

                $activation = null;

                foreach ($pendingActivations as $item) {
                    if (Hash::check($validated['otp'], $item->otp)) {
                        $activation = $item;
                        break;
                    }
                }

                if (!$activation) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Code OTP invalide, expiré ou déjà utilisé pour cet établissement.',
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $license = $activation->license;

                if (!$license) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Licence associée introuvable.',
                    ], Response::HTTP_NOT_FOUND);
                }

                $now = Carbon::now();
                $startsAt = $activation->starts_at ? Carbon::parse($activation->starts_at) : $now->copy();

                if ($activation->expires_at) {
                    $expiresAt = Carbon::parse($activation->expires_at);
                } else {
                    $expiresAt = match ($license->duration_unit) {
                        'days'   => (clone $startsAt)->addDays((int) $license->duration_value),
                        'weeks'  => (clone $startsAt)->addWeeks((int) $license->duration_value),
                        'months' => (clone $startsAt)->addMonths((int) $license->duration_value),
                        'years'  => (clone $startsAt)->addYears((int) $license->duration_value),
                        default  => (clone $startsAt)->addDays((int) $license->duration_value),
                    };
                }
                EstablishmentActivation::where('establishment_uuid', $establishmentUuid)
                    ->where('id', '!=', $activation->id)
                    ->where('is_active', true)
                    ->update([
                        'is_active'  => false,
                        'updated_by' => auth()->id(),
                    ]);

                $activation->update([
                    'starts_at'  => $startsAt,
                    'expires_at' => $expiresAt,
                    'is_active'  => true,
                    'status'     => ActivationStatus::ACTIVE,
                    'is_used'    => true,
                    'updated_by' => auth()->id(),
                ]);

                $establishment = Establishment::where('uuid', $establishmentUuid)->first();
                if ($establishment) {
                    $establishment->update([
                        'license_uuid'    => $activation->license_uuid,
                        'is_used_licence' => true,
                    ]);
                }

                return response()->json([
                    'status'  => true,
                    'message' => 'Établissement activé avec succès !',
                    'data'    => $activation->fresh('license'),
                ], Response::HTTP_OK);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de l’activation de l’établissement.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

}
