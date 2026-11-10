<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegulationMethodRequest;
use App\Models\RegulationMethod;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/**
 * @permission_category Modes de règlements
 */
class RegulationMethodController extends Controller
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
     * @return JsonResponse
     *
     * @permission RegulationMethodController::index
     * @code BAR, RESTAURANT
     * @permission_desc Afficher la liste des modes de règlement
     */
    public function index()
    {
        return response()->json(
            RegulationMethod::with(['creator:id,nom_utilisateur','updater'])
                ->when(request()->input('active'), function ($query) {
                    $query->where('active', request()->input('active'));
                })
                ->get()
        );
    }

    /**
     * @param RegulationMethodRequest $request
     * @return JsonResponse
     *
     * @permission RegulationMethodController::store
     * @code BAR, RESTAURANT
     * @permission_desc Créer un mode de règlement
     */
    public function store(RegulationMethodRequest $request,LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin(auth()->user());

        $data = array_merge($request->validated(), [
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
        ]);

        RegulationMethod::create($data);

        return response()->json([
            'message' => __('Enregistrement effectué avec succès')
        ]);
    }

    /**
     * @param RegulationMethodRequest $request
     * @param RegulationMethod $regulationMethod
     * @return JsonResponse
     *
     * @permission RegulationMethodController::update
     * @code BAR, RESTAURANT
     * @permission_desc Modifier un mode de règlement
     */
    public function update(RegulationMethodRequest $request, RegulationMethod $regulationMethod,LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin(auth()->user());

        $data = array_merge($request->validated(), [
            'updated_by' => $auth->id
        ]);
        $regulationMethod->update($data);

        return response()->json([
            'message' => 'Mise à jour effectuée avec succès'
        ]);
    }

    /**
     * @param RegulationMethod $regulationMethod
     * @return JsonResponse
     *
     * @permission RegulationMethodController::activate
     * @code BAR, RESTAURANT
     * @permission_desc Activer/Désactiver un mode de règlement
     */
    public function activate(RegulationMethod $regulationMethod)
    {
        $regulationMethod->active = !$regulationMethod->active;
        $regulationMethod->save();

        return response()->json([], 200);
    }
    //
}
