<?php

namespace App\Services;

use App\Enums\ActivationStatus;
use App\Models\Establishment;
use App\Models\License;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LicenseService
{
    public function generate(array $data, ?int $userId): License
    {
        $durationValue = $data['duration_value'];
        $durationUnit = $data['duration_unit'];

        $startsAt = Carbon::now();

        $expiresAt = match ($durationUnit) {
            'days' => (clone $startsAt)->addDays($durationValue),
            'weeks' => (clone $startsAt)->addWeeks($durationValue),
            'months' => (clone $startsAt)->addMonths($durationValue),
            'years' => (clone $startsAt)->addYears($durationValue),
            default => (clone $startsAt)->addDays($durationValue),
        };

        $licenseKey = 'LIC-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));

        $dataToSign = [
            'license_key' => $licenseKey,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $signature = hash_hmac('sha256', json_encode($dataToSign), config('app.key'));

        return License::create([
            'name'           => $data['name'],
            'price'          => $data['price'],
            'license_key'    => $licenseKey,
            'signature'      => $signature,
            'payload'        => $data['payload'] ?? null,
            'duration_value' => $durationValue,
            'duration_unit'  => $durationUnit,
            'starts_at'      => $startsAt,
            'expires_at'     => $expiresAt,
            'is_active'      => true,
            'created_by'     => $userId,
            'updated_by'     => $userId,
        ]);
    }

    /**
     * Met à jour une licence existante et recalcule sa signature si nécessaire.
     */
    public function update(License $license, array $data, ?int $userId): License
    {
        $durationValue = $data['duration_value'];
        $durationUnit = $data['duration_unit'];

        $startsAt = $license->starts_at ?? Carbon::now();

        $expiresAt = match ($durationUnit) {
            'days' => (clone $startsAt)->addDays($durationValue),
            'weeks' => (clone $startsAt)->addWeeks($durationValue),
            'months' => (clone $startsAt)->addMonths($durationValue),
            'years' => (clone $startsAt)->addYears($durationValue),
            default => (clone $startsAt)->addDays($durationValue),
        };
        $dataToSign = [
            'license_key' => $license->license_key,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $signature = hash_hmac('sha256', json_encode($dataToSign), config('app.key'));

        $license->update([
            'name'           => $data['name'],
            'price'          => $data['price'],
            'signature'      => $signature,
            'payload'        => $data['payload'] ?? $license->payload,
            'duration_value' => $durationValue,
            'duration_unit'  => $durationUnit,
            'expires_at'     => $expiresAt,
            'is_active'      => $data['is_active'] ?? $license->is_active,
            'updated_by'     => $userId,
        ]);

        return $license;
    }

    /**
     * Vérifie si la licence est valide et si sa signature n'a pas été falsifiée.
     */
    public function verify(License $license): bool
    {
        if (!$license->is_active || Carbon::now()->greaterThan($license->expires_at)) {
            return false;
        }

        $dataToSign = [
            'license_key' => $license->license_key,
            'expires_at' => Carbon::parse($license->expires_at)->toIso8601String(),
        ];

        $expectedSignature = hash_hmac('sha256', json_encode($dataToSign), config('app.key'));

        return hash_equals($expectedSignature, $license->signature);
    }

    public function validateActiveLicense(Request $request)
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (empty($establishmentUuid)) {
            return response()->json([
                'status'  => false,
                'message' => 'L’en-tête X-Establishment-Uuid est manquant. Veuillez sélectionner un établissement.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $establishment = Establishment::with(['license', 'activeActivation'])
            ->where('uuid', $establishmentUuid)
            ->first();

        if (!$establishment) {
            return response()->json([
                'status'  => false,
                'message' => 'Établissement introuvable.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (empty($establishment->license_uuid) || !$establishment->license) {
            return response()->json([
                'status'  => false,
                'message' => 'Action impossible : Aucun abonnement n’est assigné à cet établissement.',
            ], Response::HTTP_FORBIDDEN);
        }

        $activation = $establishment->activeActivation;

        if (!$activation || $activation->status === ActivationStatus::PENDING) {
            return response()->json([
                'status'  => false,
                'message' => 'Action impossible : L’abonnement de cet établissement est en attente d’activation. Veuillez l’activer avec votre code OTP.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($activation->status === ActivationStatus::EXPIRED || now()->greaterThan($activation->expires_at)) {
            return response()->json([
                'status'  => false,
                'message' => 'Action impossible : L’abonnement de cet établissement a expiré. Veuillez le renouveler.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$activation->is_active || $activation->status === ActivationStatus::SUSPENDED) {
            return response()->json([
                'status'  => false,
                'message' => 'Action impossible : L’abonnement de cet établissement est suspendu ou inactif.',
            ], Response::HTTP_FORBIDDEN);
        }
        return null;
    }
}
