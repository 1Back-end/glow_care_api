<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivationStatus;
use App\Models\Establishment;
use App\Models\EstablishmentActivation;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
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

            $expiresAt = match ($license->duration_unit) {
                'days'   => (clone $now)->addDays($license->duration_value),
                'weeks'  => (clone $now)->addWeeks($license->duration_value),
                'months' => (clone $now)->addMonths($license->duration_value),
                'years'  => (clone $now)->addYears($license->duration_value),
                default  => (clone $now)->addDays($license->duration_value),
            };

            $activation->update([
                'starts_at'  => $now,
                'expires_at' => $expiresAt,
                'is_active'  => true,
                'status'     => ActivationStatus::ACTIVE,
                'is_used'    => true,
                'updated_by' => auth()->id(),
            ]);

            $establishment = Establishment::where('uuid', $establishmentUuid)->first();
            if ($establishment) {
                $establishment->update([
                    'is_used_licence' => true,
                ]);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Établissement activé avec succès !',
                'data'    => $activation->load('license'),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de l’activation de l’établissement.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

}
