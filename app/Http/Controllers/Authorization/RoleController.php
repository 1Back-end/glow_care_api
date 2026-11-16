<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authorization\RoleRequest;
use App\Models\Centre;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * @permission_category Gestion des roles
 */
class RoleController extends Controller
{
    protected LicenseService $licenseService;
    public function __construct(LicenseService $licenseService)
    {
        $this->licenseService = $licenseService;
    }
    /**
     * @return JsonResponse
     *
     * @permission RoleController::index
     * @permission_desc Liste de tous les roles
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $query = Role::with([
            'createdBy:id,nom_utilisateur',
            'updatedBy:id,nom_utilisateur',
            'permissions:id,name,description',
            'establishment:uuid,name',
        ])
            ->when($currentUser?->is_super_admin, function ($q) {
                $q->where('is_super_admin', true);
            }, function ($q) {
                $q->where('is_client', true);
            })
            ->when(!$currentUser?->is_super_admin && $establishmentUuid, function ($q) use ($establishmentUuid) {
                $q->where('establishment_uuid', $establishmentUuid);
            })
            ->when(trim($request->input('search')), function ($q, $search) {
                $search = trim($search);
                $q->where(function ($subQ) use ($search) {
                    $subQ->where('id', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('guard_name', 'like', "%{$search}%")
                        ->orWhereHas('permissions', function ($qw) use ($search) {
                            $qw->where('id', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%")
                                ->orWhere('description', 'like', "%{$search}%");
                        });
                });
            });

        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }

    /**
     * @param RoleRequest $request
     * @return JsonResponse
     *
     * @permission RoleController::store
     * @permission_desc Créer un role
     * @throws \Throwable
     */
    public function store(RoleRequest $request, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $establishmentUuid = $request->header('X-Establishment-Uuid');
        $currentUser = auth()->user();

        DB::beginTransaction();
        try {
            $roleData = $request->validated();
            $roleData['name'] = strtoupper($roleData['name']);

            if ($establishmentUuid) {
                $roleData['establishment_uuid'] = $establishmentUuid;
            }

            $roleData['is_super_admin'] = (bool) ($currentUser?->is_super_admin ?? false);
            $roleData['is_client']      = !$roleData['is_super_admin'] && (bool) ($currentUser?->is_client ?? false);
            $roleData['created_by']     = $currentUser?->id;
            $roleData['updated_by']     = $currentUser?->id;

            $role = Role::create($roleData);

            if ($request->has('permissions') && is_array($request->input('permissions'))) {
                foreach ($request->input('permissions') as $permission) {
                    $permissionId = is_array($permission) ? ($permission['id'] ?? null) : $permission;

                    if ($permissionId) {
                        $role->permissions()->attach($permissionId, [
                            'created_by'         => $currentUser?->id,
                            'updated_by'         => $currentUser?->id,
                            'establishment_uuid' => $establishmentUuid,
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => __("Le rôle a été créé avec succès !")
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return response()->json([
                'message' => __("Une erreur s'est produite lors de la création du rôle !")
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * @param Role $role
     * @return JsonResponse
     *
     * @permission RoleController::show
     * @permission_desc Afficher un role
     */
    public function show(Role $role)
    {
        return response()->json([
            'role' => $role->load(['permissions:id,name']),
        ]);
    }

    /**
     * @param RoleRequest $request
     * @param Role $role
     * @return JsonResponse
     *
     * @permission RoleController::update
     * @permission_desc Mise à jour d’un rôle
     * @throws \Throwable
     */
    public function update(RoleRequest $request, Role $role, LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $establishmentUuid = $request->header('X-Establishment-Uuid');
        $currentUser = auth()->user();

        DB::beginTransaction();
        try {
            $data = $request->except(['is_super_admin', 'is_client', 'is_sub_user']);

            if ($role->id === 1) {
                unset($data['name']);
            } else {
                if (isset($data['name'])) {
                    $data['name'] = strtoupper($data['name']);
                }
            }

            if ($currentUser?->is_super_admin) {
                $data['is_super_admin'] = true;
                $data['is_client'] = false;
            } elseif ($currentUser?->is_client) {
                $data['is_super_admin'] = false;
                $data['is_client'] = true;
            }

            if ($establishmentUuid) {
                $data['establishment_uuid'] = $establishmentUuid;
            }

            $data['updated_by'] = $currentUser?->id;

            $role->update($data);

            if ($request->has('permissions')) {
                $role->permissions()->detach();

                $permissionsInput = $request->input('permissions');
                if (!empty($permissionsInput) && is_array($permissionsInput)) {
                    foreach ($permissionsInput as $permission) {
                        $permissionId = is_array($permission) ? ($permission['id'] ?? null) : $permission;

                        if ($permissionId) {
                            $role->permissions()->attach($permissionId, [
                                'created_by'         => $currentUser?->id,
                                'updated_by'         => $currentUser?->id,
                                'establishment_uuid' => $establishmentUuid,
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => __("Mise à jour du rôle effectuée avec succès !"),
                'role'    => $role,
                'validated' => $request->validated()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return response()->json([
                'message' => __("Une erreur s'est produite lors de la mise à jour du rôle !")
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * @param Role $role
     * @param int $activate
     * @return JsonResponse
     *
     * @permission RoleController::activate
     * @permission_desc Désactiver / Activer un rôle
     */
    public function activate(Role $role, int $activate): JsonResponse
    {
        $role->update([
            'active' => $activate,
        ]);

        return \response()->json([
            'message' => __("Le role a été " . $activate ? 'activé' : 'désactivé' . " avec succès !"),
        ]);
    }

    /**
     * @param Role $role
     * @param User $user
     * @param int $activate
     * @return JsonResponse
     *
     * @permission RoleController::activateRoleToUser
     * @permission_desc Activate / Désactiver un rôle à un utilisateur
     */
    public function activateRoleToUser(Role $role, User $user, int $activate)
    {
        $role->permissions()->updateExistingPivot($user->id, [
            'active' => $activate,
            'updated_by' => auth()->id()
        ]);

        return \response()->json([
            'message' => __("Le rôle a été " . $activate ? 'activé' : 'désactivé' . " avec succès pour cet utilisateur !"),
        ]);
    }
}
