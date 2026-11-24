<?php

namespace App\Http\Controllers\API\V1;

use App\Enums\CashRegisterStatus;
use App\Enums\CashRegisterType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashRegisterRequest;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use App\Models\UserEstablishment;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @permission_category Gestion des caisses
 */
class CashRegisterController extends Controller
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
     * @return \Illuminate\Http\JsonResponse
     *
     * @permission CashRegisterController::index
     * @permission_desc Afficher la liste des caisses
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $currentUser = auth()->user();
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $query = CashRegister::with(['establishment:uuid,name', 'user:id,nom_utilisateur', 'creator:id,nom_utilisateur', 'updater:id,nom_utilisateur'])
            ->when($establishmentUuid, function ($q) use ($establishmentUuid) {
                $q->where('establishment_uuid', $establishmentUuid);
            })
            ->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser) {
                $userEstablishmentUuids = UserEstablishment::where('user_id', $currentUser->id)
                    ->pluck('establishment_uuid');
                $q->whereIn('establishment_uuid', $userEstablishmentUuids);
            })
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->filled('type'), function ($query) use ($request) {
                $query->where('type', $request->input('type'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            });

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $cashRegisters = $query->orderBy('name', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $cashRegisters->items(),
            'current_page' => $cashRegisters->currentPage(),
            'last_page'    => $cashRegisters->lastPage(),
            'total'        => $cashRegisters->total(),
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * @permission CashRegisterController::store
     * @permission_desc Créer une caisse
     */
    public function store(Request $request, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (empty($establishmentUuid)) {
            throw ValidationException::withMessages([
                'establishment_uuid' => ["L'en-tête X-Establishment-Uuid est requis."],
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'secret_key' => ['nullable', 'string', 'min:8', 'max:255'],
            'initial_balance' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['required', Rule::enum(CashRegisterType::class)],
            'status' => ['sometimes', Rule::enum(CashRegisterStatus::class)],
            'user_id' => ['nullable', Rule::exists('users', 'id')],
        ]);

        $plainSecretKey = $validated['secret_key'] ?? 'CR12345@2026';
        $initialBalance = round((float) $validated['initial_balance'], 2);
        $type = $validated['type'] instanceof CashRegisterType
            ? $validated['type']
            : CashRegisterType::from($validated['type']);
        $userId = $validated['user_id'] ?? null;

        $cashRegister = DB::transaction(function () use (
            $validated,
            $type,
            $userId,
            $plainSecretKey,
            $establishmentUuid,
            $auth,
            $initialBalance
        ) {
            if ($type === CashRegisterType::MAIN) {
                $existsMain = CashRegister::where('establishment_uuid', $establishmentUuid)
                    ->where('type', CashRegisterType::MAIN->value)
                    ->lockForUpdate()
                    ->exists();

                if ($existsMain) {
                    throw ValidationException::withMessages([
                        'type' => ["Une caisse principale existe déjà pour cet établissement."],
                    ]);
                }
            }

            if ($userId !== null) {
                $userAlreadyHasCashRegister = CashRegister::where('establishment_uuid', $establishmentUuid)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->exists();

                if ($userAlreadyHasCashRegister) {
                    throw ValidationException::withMessages([
                        'user_id' => ["Cet utilisateur est déjà affecté à une caisse dans cet établissement."],
                    ]);
                }
            }

            return CashRegister::create([
                'establishment_uuid' => $establishmentUuid,
                'created_by' => $auth->id,
                'updated_by' => $auth->id,
                'name' => trim($validated['name']),
                'secret_key' => Hash::make($plainSecretKey),
                'initial_balance' => $initialBalance,
                'current_balance' => $initialBalance,
                'is_active' => $validated['is_active'] ?? true,
                'type' => $type->value,
                'status' => $validated['status'] ?? CashRegisterStatus::CLOSED->value,
                'user_id' => $userId,
            ]);
        });

        return response()->json([
            'message' => 'Caisse créée avec succès.',
            'data' => $cashRegister->fresh(),
            'secret_key' => $plainSecretKey,
        ], 201);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * @permission CashRegisterController::update
     * @permission_desc Modifier une caisse
     */
    public function update(Request $request, string $uuid, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (empty($establishmentUuid)) {
            throw ValidationException::withMessages([
                'establishment_uuid' => ["L'en-tête X-Establishment-Uuid est requis."],
            ]);
        }

        $cashRegister = CashRegister::where('uuid', $uuid)
            ->where('establishment_uuid', $establishmentUuid)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'secret_key' => ['nullable', 'string', 'min:8', 'max:255'],
            'initial_balance' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'type' => ['sometimes', Rule::enum(CashRegisterType::class)],
            'status' => ['sometimes', Rule::enum(CashRegisterStatus::class)],
            'user_id' => ['nullable', Rule::exists('users', 'id')],
        ]);

        $newType = null;
        if (array_key_exists('type', $validated)) {
            $newType = $validated['type'] instanceof CashRegisterType
                ? $validated['type']
                : CashRegisterType::from($validated['type']);
        }

        $hasUserId = array_key_exists('user_id', $validated);
        $newUserId = $hasUserId ? $validated['user_id'] : null;

        $updatedCashRegister = DB::transaction(function () use (
            $validated,
            $newType,
            $hasUserId,
            $newUserId,
            $cashRegister,
            $establishmentUuid,
            $auth
        ) {
            if ($newType === CashRegisterType::MAIN) {
                $existsMain = CashRegister::where('establishment_uuid', $establishmentUuid)
                    ->where('type', CashRegisterType::MAIN->value)
                    ->where('uuid', '!=', $cashRegister->uuid)
                    ->lockForUpdate()
                    ->exists();

                if ($existsMain) {
                    throw ValidationException::withMessages([
                        'type' => ["Une caisse principale existe déjà pour cet établissement."],
                    ]);
                }
            }

            if ($hasUserId && $newUserId !== null) {
                $userAlreadyHasCashRegister = CashRegister::where('establishment_uuid', $establishmentUuid)
                    ->where('user_id', $newUserId)
                    ->where('uuid', '!=', $cashRegister->uuid)
                    ->lockForUpdate()
                    ->exists();

                if ($userAlreadyHasCashRegister) {
                    throw ValidationException::withMessages([
                        'user_id' => ["Cet utilisateur est déjà affecté à une caisse dans cet établissement."],
                    ]);
                }
            }

            $payload = [
                'updated_by' => $auth->id,
            ];

            if (array_key_exists('name', $validated)) {
                $payload['name'] = trim($validated['name']);
            }

            if (!empty($validated['secret_key'])) {
                $payload['secret_key'] = Hash::make($validated['secret_key']);
            }

            if (array_key_exists('initial_balance', $validated)) {
                $newInitialBalance = round((float) $validated['initial_balance'], 2);
                $diff = round($newInitialBalance - round((float) $cashRegister->initial_balance, 2), 2);

                $payload['initial_balance'] = $newInitialBalance;
                $payload['current_balance'] = round((float) $cashRegister->current_balance + $diff, 2);
            }

            if ($newType !== null) {
                $payload['type'] = $newType->value;
            }

            if ($hasUserId) {
                $payload['user_id'] = $newUserId;
            }

            foreach (['is_active', 'status'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            $cashRegister->update($payload);

            return $cashRegister->fresh(['establishment', 'user', 'creator', 'updater']);
        });

        return response()->json([
            'message' => 'Caisse mise à jour avec succès.',
            'data' => $updatedCashRegister,
        ]);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * @permission CashRegisterController::updateStatus
     * @permission_desc Activer/Désactiver une caisse
     */
    public function updateStatus(Request $request, string $uuid, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (empty($establishmentUuid)) {
            throw ValidationException::withMessages([
                'establishment_uuid' => ["L'en-tête X-Establishment-Uuid est requis."],
            ]);
        }

        $validated = $request->validate([
            'is_active' => ['nullable', 'boolean'],
        ]);

        $cashRegister = CashRegister::where('uuid', $uuid)
            ->where('establishment_uuid', $establishmentUuid)
            ->firstOrFail();

        $isActive = array_key_exists('is_active', $validated) && $validated['is_active'] !== null
            ? (bool) $validated['is_active']
            : !$cashRegister->is_active;

        $cashRegister->update([
            'is_active' => $isActive,
            'updated_by' => $auth->id,
        ]);

        return response()->json([
            'status' => true,
            'message' => $isActive ? 'Caisse activée avec succès.' : 'Caisse désactivée avec succès.',
            'data' => $cashRegister->fresh(),
        ]);
    }

    public function show(Request $request, string $uuid, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (empty($establishmentUuid)) {
            throw ValidationException::withMessages([
                'establishment_uuid' => ["L'en-tête X-Establishment-Uuid est requis."],
            ]);
        }

        $cashRegister = CashRegister::with(['establishment', 'user', 'creator', 'updater'])
            ->where('uuid', $uuid)
            ->where('establishment_uuid', $establishmentUuid)
            ->firstOrFail();

        return response()->json([
            'status' => true,
            'message' => 'Détails de la caisse récupérés avec succès.',
            'data' => $cashRegister,
        ]);
    }

    public function getByUserAndEstablishment(Request $request): JsonResponse
    {
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $auth = auth()->user();

        $cashRegister = CashRegister::with(['establishment:uuid,name', 'user:id,nom_utilisateur,login'])
            ->where('establishment_uuid', $establishmentUuid)
            ->where('user_id', $auth->id)
            ->where('is_active', true)
            ->first();

        if (!$cashRegister) {
            return response()->json([
                'status'  => false,
                'message' => 'Aucune caisse trouvée pour cet utilisateur dans cet établissement.',
                'data'    => null,
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $cashRegister,
        ]);
    }
    private function isValidSecretKey(CashRegister $cashRegister, string $secretKey): bool
    {
        $hashedKey = (string) $cashRegister->makeVisible('secret_key')->secret_key;
        if ($hashedKey === '') {
            return false;
        }
        return Hash::check($secretKey, $hashedKey);
    }
    public function open(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'secret_key' => ['required', 'string'],
        ]);

        $cashRegister = CashRegister::where('uuid', $uuid)
            ->where('is_active', true)
            ->firstOrFail();

        if (!$this->isValidSecretKey($cashRegister, $validated['secret_key'])) {
            return response()->json([
                'status'  => false,
                'message' => 'Code secret incorrect.',
            ], 422);
        }

        $currentStatus = $cashRegister->status?->value ?? $cashRegister->status;

        if ($currentStatus === 'open') {
            return response()->json([
                'status'  => false,
                'message' => 'Cette caisse est déjà ouverte.',
            ], 422);
        }

        $userId = auth()->id();
        $now = now();

        $openingBalance = (float) ($cashRegister->current_balance ?? $cashRegister->initial_balance ?? 0);

        $session = DB::transaction(function () use ($cashRegister, $openingBalance, $userId, $now) {
            CashRegisterSession::where('cash_register_uuid', $cashRegister->uuid)
                ->whereIn('status', ['open', 'paused'])
                ->update([
                    'status'     => 'closed',
                    'closed_at'  => $now,
                    'closed_by'  => $userId,
                    'updated_by' => $userId,
                ]);

            $createdSession = CashRegisterSession::create([
                'cash_register_uuid' => $cashRegister->uuid,
                'establishment_uuid' => $cashRegister->establishment_uuid,
                'user_id'            => $userId,
                'status'             => 'open',
                'opening_balance'    => $openingBalance,
                'opened_at'          => $now,
                'opened_by'          => $userId,
                'created_by'         => $userId,
                'updated_by'         => $userId,
            ]);

            $cashRegister->update([
                'status'          => 'open',
                'current_balance' => $openingBalance,
                'updated_by'      => $userId,
            ]);

            return $createdSession;
        });

        return response()->json([
            'status'  => true,
            'message' => 'Caisse ouverte et session démarrée avec succès.',
            'data'    => [
                'cash_register' => $cashRegister->fresh(['establishment', 'user']),
                'session'       => $session,
            ],
        ]);
    }

    public function close(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'secret_key'   => ['required', 'string'],
            'closing_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $cashRegister = CashRegister::where('uuid', $uuid)
            ->where('is_active', true)
            ->firstOrFail();

        if (!$this->isValidSecretKey($cashRegister, $validated['secret_key'])) {
            return response()->json([
                'status'  => false,
                'message' => 'Code secret incorrect.',
            ], 422);
        }

        $currentStatus = $cashRegister->status?->value ?? $cashRegister->status;

        if ($currentStatus === 'closed') {
            return response()->json([
                'status'  => false,
                'message' => 'Cette caisse est déjà fermée.',
            ], 422);
        }

        $userId = auth()->id();
        $now = now();

        $session = DB::transaction(function () use ($cashRegister, $validated, $userId, $now) {
            $activeSession = CashRegisterSession::where('cash_register_uuid', $cashRegister->uuid)
                ->whereIn('status', ['open', 'paused'])
                ->latest('opened_at')
                ->first();

            $balance = (float) ($cashRegister->current_balance ?? 0);

            if ($activeSession) {
                $activeSession->update([
                    'status'                 => 'closed',
                    'expected_closing_balance' => $balance,
                    'closing_balance'        => $balance,
                    'difference'             => 0,
                    'closed_at'              => $now,
                    'closing_note'           => $validated['closing_note'] ?? null,
                    'closed_by'              => $userId,
                    'updated_by'             => $userId,
                ]);
            }

            $cashRegister->update([
                'status'     => 'closed',
                'updated_by' => $userId,
            ]);

            return $activeSession?->fresh();
        });

        return response()->json([
            'status'  => true,
            'message' => 'Caisse fermée et session clôturée avec succès.',
            'data'    => [
                'cash_register' => $cashRegister->fresh(['establishment', 'user']),
                'session'       => $session,
            ],
        ]);
    }
}
