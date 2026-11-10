<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PrestationCategory;
use App\Http\Controllers\Controller;
use App\Models\Prestation;
use App\Models\UserEstablishment;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @permission_category Gestion des prestations
 */

class PrestationController extends Controller
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
     * @permission PrestationController::index
     * @permission_desc Afficher la liste des prestations
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $currentUser = auth()->user();

        $query = Prestation::with(['creator:id,nom_utilisateur', 'updater:id,nom_utilisateur', 'establishment:uuid,code,name'])
            ->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser) {
                $userEstablishmentUuids = UserEstablishment::where('user_id', $currentUser->id)
                    ->pluck('establishment_uuid');

                $q->whereIn('establishment_uuid', $userEstablishmentUuids);
            })
            ->when($request->filled('start_date') && $request->filled('end_date'), function ($query) use ($request) {
                $query->whereBetween('programmation_date', [
                    $request->input('start_date') . ' 00:00:00',
                    $request->input('end_date') . ' 23:59:59'
                ]);
            })

            ->when($request->has('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            })

            ->when(trim($request->input('search')), function ($q, $search) {
                $q->where(function ($subQ) use ($search) {
                    $subQ->where('name', 'like', "%{$search}%")
                        ->orWhere('category', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            });

        $prestations = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $prestations->items(),
            'current_page' => $prestations->currentPage(),
            'last_page'    => $prestations->lastPage(),
            'total'        => $prestations->total(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission PrestationController::store
     * @permission_desc Créer une prestation
     */
    public function store(Request $request, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $currentUser = auth()->user();
        $this->authService->denyIfSuperAdmin($currentUser);

        if ($establishmentUuid = $request->header('X-Establishment-Uuid')) {
            $request->merge(['establishment_uuid' => $establishmentUuid]);
        }

        $validator = Validator::make($request->all(), [
            'establishment_uuid' => 'required|uuid|exists:establishments,uuid',
            'name'               => 'required|string|max:255',
            'category'           => ['nullable', 'string', Rule::enum(PrestationCategory::class)],
            'price'              => 'required|numeric|min:0',
            'duration'           => 'required|integer|min:1',
            'description'        => 'nullable|string',
            'file'               => 'nullable|file|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $validator->validated();
            $data['created_by'] = $currentUser?->id;

            $prestation = Prestation::create($data);

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $filename = time() . '_logo_' . $file->getClientOriginalName();
                $path = $file->store('establishments/file', 'public');

                $prestation->update(['file' => $path]);

                if (method_exists($prestation, 'medias')) {
                    $prestation->medias()->create([
                        'name'      => $filename,
                        'disk'      => 'public',
                        'path'      => $path,
                        'filename'  => $filename,
                        'mimetype'  => $file->getClientMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                    ]);
                }
            }

            return response()->json([
                'status'  => true,
                'message' => 'Prestation créée avec succès.',
                'data'    => $prestation->load(['creator', 'establishment'])
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erreur création prestation : ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Une erreur est survenue lors de la création de la prestation.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission PrestationController::update
     * @permission_desc Modifier une prestation
     */
    public function update(Request $request, LicenseService $licenseService, $uuid): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $currentUser = auth()->user();
        if (method_exists($this, 'authService') && $this->authService) {
            $this->authService->denyIfSuperAdmin($currentUser);
        }

        $prestation = Prestation::find($uuid);

        if (!$prestation) {
            return response()->json([
                'status'  => false,
                'message' => 'Prestation introuvable.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'establishment_uuid' => 'sometimes|required|uuid|exists:establishments,uuid',
            'name'               => 'sometimes|required|string|max:255',
            'category'           => ['nullable', 'string', Rule::enum(PrestationCategory::class)],
            'price'              => 'sometimes|required|numeric|min:0',
            'duration'           => 'sometimes|required|integer|min:1',
            'description'        => 'nullable|string',
            'file'               => 'nullable|file|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $data['updated_by'] = $currentUser?->id;

        $prestation->update($data);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filename = time() . '_logo_' . $file->getClientOriginalName();
            $path = $file->store('establishments/file', 'public');

            if ($prestation->file && Storage::disk('public')->exists($prestation->file)) {
                Storage::disk('public')->delete($prestation->file);
            }

            $prestation->update(['file' => $path]);

            if (method_exists($prestation, 'medias')) {
                $prestation->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Prestation mise à jour avec succès.',
            'data'    => $prestation->load(['creator', 'updater', 'establishment'])
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission PrestationController::show
     * @permission_desc Afficher les détails d'une prestation
     */
    public function show($uuid): JsonResponse
    {
        $prestation = Prestation::with(['creator', 'updater', 'establishment'])->find($uuid);

        if (!$prestation) {
            return response()->json([
                'status'  => false,
                'message' => 'Prestation introuvable.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $prestation
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission PrestationController::updateStatus
     * @permission_desc Activer/Désactiver une prestation
     */
    public function updateStatus(Request $request, LicenseService $licenseService, $uuid): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $currentUser = auth()->user();
        if (method_exists($this, 'authService') && $this->authService) {
            $this->authService->denyIfSuperAdmin($currentUser);
        }

        $prestation = Prestation::find($uuid);

        if (!$prestation) {
            return response()->json([
                'status'  => false,
                'message' => 'Prestation introuvable.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'is_active' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $prestation->update([
            'is_active'  => $request->input('is_active'),
            'updated_by' => $currentUser?->id
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Statut de la prestation mis à jour avec succès.',
            'data'    => $prestation->load(['creator', 'updater', 'establishment'])
        ]);
    }

}
