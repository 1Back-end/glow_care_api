<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\License;
use App\Services\LicenseService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
/**
 * @permission_category Gestion des certificats de licence
 * @is_super_admin true
 */

class LicenseController extends Controller
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
     * @permission LicenseController::index
     * @permission_desc Afficher la liste des certificats de licence
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('limit', 25);

        $licenses = License::with(['creator', 'updater'])
            ->when($request->has('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })
            ->when(trim($request->input('search')), function ($q, $search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('license_key', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status'       => true,
            'data'         => $licenses->items(),
            'current_page' => $licenses->currentPage(),
            'last_page'    => $licenses->lastPage(),
            'total'        => $licenses->total(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LicenseController::store
     * @permission_desc Créer un certificat de licence
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'price'          => 'required|numeric|min:0',
            'duration_value' => 'required|integer|min:1',
            'duration_unit'  => 'required|string|in:days,weeks,months,years',
            'payload'        => 'nullable|array',
        ]);

        $auth = auth()->user();

        $license = $this->licenseService->generate($validated, $auth?->id);

        return response()->json([
            'status'  => true,
            'message' => 'Certificat de licence généré avec succès',
            'data'    => $license
        ], 201);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LicenseController::update
     * @permission_desc Mettre à jour un certificat de licence
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'price'          => 'required|numeric|min:0',
            'duration_value' => 'required|integer|min:1',
            'duration_unit'  => 'required|string|in:days,weeks,months,years',
            'payload'        => 'nullable|array',
            'is_active'      => 'sometimes|boolean',
        ]);

        $license = License::where('uuid', $uuid)->firstOrFail();

        $auth = auth()->user();

        $updatedLicense = $this->licenseService->update($license, $validated, $auth?->id);

        return response()->json([
            'status'  => true,
            'message' => 'Certificat de licence mis à jour avec succès',
            'data'    => $updatedLicense
        ], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LicenseController::show
     * @permission_desc Mettre à jour un certificat de licence
     */
    public function show(string $uuid): JsonResponse
    {
        $license = License::with(['creator', 'updater'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $isValid = $this->licenseService->verify($license);

        return response()->json([
            'status' => true,
            'is_valid_signature' => $isValid,
            'data' => $license
        ], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission LicenseController::updateStatus
     * @permission_desc Activer/Désactiver un certificat de licence
     */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $license = License::where('uuid', $uuid)->firstOrFail();

        $license->update([
            'is_active' => $validated['is_active'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'status' => true,
            'message' => $validated['is_active'] ? 'Licence activée avec succès' : 'Licence désactivée avec succès',
            'data' => $license
        ], 200);
    }

}
