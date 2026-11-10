<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyRule;
use App\Enums\LoyaltyRewardType;
use App\Models\UserEstablishment;
use App\Services\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
/**
 * @permission_category Gestion des règles de fidélité
 */
class LoyaltyRuleController extends Controller
{
    protected LicenseService $licenseService;
    public function __construct(LicenseService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LoyaltyRuleController::index
     * @permission_desc Afficher la liste des règles de fidélité
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $currentUser = auth()->user();

        $query = LoyaltyRule::with(['creator', 'updater', 'establishment'])
            ->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser) {
                $userEstablishmentUuids = UserEstablishment::where('user_id', $currentUser->id)
                    ->pluck('establishment_uuid');
                $q->whereIn('establishment_uuid', $userEstablishmentUuids);
            })

            ->when($request->has('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })

            ->when(trim($request->input('search')), function ($q, $search) {
                $q->where(function ($subQ) use ($search) {
                    $subQ->where('name', 'like', "%{$search}%")
                        ->orWhere('reward_type', 'like', "%{$search}%")
                        ->orWhere('required_prestations', 'like', "%{$search}%");
                });
            });

        $loyaltyRules = $query->orderBy('required_prestations', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $loyaltyRules->items(),
            'current_page' => $loyaltyRules->currentPage(),
            'last_page'    => $loyaltyRules->lastPage(),
            'total'        => $loyaltyRules->total(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LoyaltyRuleController::store
     * @permission_desc Créer lees règles de fidélité
     */
    public function store(Request $request,LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $auth = auth()->user();
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'required_prestations' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('loyalty_rules', 'required_prestations')->where(function ($query) use ($establishmentUuid) {
                    return $query->where('establishment_uuid', $establishmentUuid);
                }),
            ],
            'reward_type' => ['required', Rule::enum(LoyaltyRewardType::class)],
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $validated['establishment_uuid'] = $establishmentUuid;
        $validated['created_by'] = $auth?->id;
        $validated['updated_by'] = $auth?->id;

        $rule = LoyaltyRule::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Règle de fidélité créée avec succès',
            'data' => $rule
        ], 201);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LoyaltyRuleController::show
     * @permission_desc Afficher les détails d'une règle de fidélité
     */
    public function show(string $uuid): JsonResponse
    {
        $rule = LoyaltyRule::with(['creator', 'updater', 'establishment'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'status' => true,
            'data' => $rule
        ], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LoyaltyRuleController::update
     * @permission_desc Mettre à jour les règles de fidélité
     */
    public function update(Request $request, string $uuid,LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $auth = auth()->user();
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $rule = LoyaltyRule::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'required_prestations' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::unique('loyalty_rules', 'required_prestations')
                    ->ignore($rule->uuid, 'uuid')
                    ->where(function ($query) use ($establishmentUuid, $rule) {
                        return $query->where('establishment_uuid', $establishmentUuid ?? $rule->establishment_uuid);
                    }),
            ],
            'reward_type' => ['sometimes', 'required', Rule::enum(LoyaltyRewardType::class)],
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($establishmentUuid) {
            $validated['establishment_uuid'] = $establishmentUuid;
        }

        $validated['updated_by'] = $auth?->id;

        $rule->update($validated);

        $rule->load(['creator', 'updater', 'establishment']);

        return response()->json([
            'status' => true,
            'message' => 'Règle de fidélité mise à jour avec succès',
            'data' => $rule
        ], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LoyaltyRuleController::updateStatus
     * @permission_desc Activer/Désactiver les règles de fidélité
     */
    public function updateStatus(Request $request, string $uuid,LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $auth = auth()->user();

        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $loyaltyRule = LoyaltyRule::where('uuid', $uuid)->firstOrFail();

        $loyaltyRule->is_active = $request->is_active;
        $loyaltyRule->updated_by = $auth?->id;
        $loyaltyRule->save();

        return response()->json([
            'status'  => true,
            'message' => 'Statut de la règle de fidélité mis à jour avec succès',
            'data'    => $loyaltyRule
        ], 200);
    }
}
