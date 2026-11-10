<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Notifications\DefaultUserCreated;
use App\Services\LicenseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;


/**
 * @permission_category Gestion des utilisateurs
 * @is_super_admin false
 */
class UserController extends Controller
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
     * @permission UserController::index
     * @permission_desc Afficher la liste des utilisateurs
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = auth()->user();
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $users = User::query()->where('is_super_admin',false)
            ->when(!$currentUser?->is_super_admin && $establishmentUuid, function ($query) use ($establishmentUuid) {
                $query->where(function ($q) use ($establishmentUuid) {
                    $q->where('establishment_uuid', $establishmentUuid)
                        ->orWhereHas('establishments', function ($subQ) use ($establishmentUuid) {
                            $subQ->where('establishments.uuid', $establishmentUuid);
                        });
                });
            })
            ->when($request->input('roles'), function ($query) use ($request) {
                $query->whereHas('roles', function ($q) use ($request) {
                    $q->whereIn('id', $request->input('roles'));
                });
            })
            ->when($request->input('permissions'), function ($query) use ($request) {
                $query->whereHas('permissions', function ($q) use ($request) {
                    $q->whereIn('id', $request->input('permissions'));
                });
            })
            ->when($request->input('search'), function ($query) use ($request) {
                $search = trim($request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('login', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('prenom', 'like', "%{$search}%");
                });
            })
            ->when($request->has('status'), function ($query) use ($request) {
                $query->where('status', $request->boolean('status'));
            })
            ->whereNotIn('login', ['SYSTEM', 'admin'])
            ->with([
                'roles:id,name',
                'permissions:id,name',
                'establishments:uuid,name',
                'createdBy:id,nom_utilisateur',
                'updatedBy:id,nom_utilisateur',
            ])
            ->latest()
            ->paginate(
                perPage: $request->input('per_page', 25),
                page: $request->input('page', 1)
            );

        return response()->json([
            'users' => $users,
        ]);
    }


    /**
     * @param UserRequest $request
     * @return JsonResponse
     *
     * @permission UserController::store
     * @permission_desc Créer un utilisateur
     * @throws \Throwable
     */
    public function store(UserRequest $request,LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $establishmentUuidHeader = $request->header('X-Establishment-Uuid');

        DB::beginTransaction();
        try {
            $defaultPassword = '1234567';

            $user = User::create(array_merge(
                $request->validated(),
                [
                    'password'           => Hash::make($defaultPassword),
                    'default'            => true,
                    'establishment_uuid' => $establishmentUuidHeader,
                    'updated_by' => auth()->id()
                ]
            ));

            if ($request->has('roles')) {
                foreach ($request->roles as $role) {
                    $user->roles()->attach($role, [
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id()
                    ]);
                }
            }

            $establishments = $request->input('establishments', []);

            if (!empty($establishments) && is_array($establishments)) {
                $defaultEstablishmentUuid = null;

                foreach ($establishments as $estData) {
                    $estUuid = is_array($estData)
                        ? ($estData['uuid'] ?? $estData['establishment_uuid'] ?? null)
                        : $estData;

                    if ($estUuid) {
                        $isDefault = is_array($estData) ? filter_var($estData['default'] ?? false, FILTER_VALIDATE_BOOLEAN) : false;

                        \App\Models\UserEstablishment::create([
                            'user_id'            => $user->id,
                            'establishment_uuid' => $estUuid,
                            'default'            => $isDefault,
                            'created_by'         => auth()->id(),
                            'updated_by'         => auth()->id(),
                        ]);

                        if ($isDefault) {
                            $defaultEstablishmentUuid = $estUuid;
                        }
                    }
                }

                if ($defaultEstablishmentUuid) {
                    $user->update(['establishment_uuid' => $defaultEstablishmentUuid]);
                }
            } elseif ($establishmentUuidHeader) {
                \App\Models\UserEstablishment::create([
                    'user_id'            => $user->id,
                    'establishment_uuid' => $establishmentUuidHeader,
                    'default'            => true,
                    'created_by'         => auth()->id(),
                    'updated_by'         => auth()->id(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message'  => __("L'utilisateur a été créé avec succès !"),
                'login'    => $user->login,
                'password' => $defaultPassword
            ], Response::HTTP_CREATED);

        } catch (\Exception $th) {
            DB::rollBack();
            Log::error($th->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur est survenue lors de la création de l'utilisateur.",
                'error'   => $th->getMessage(),
            ], 500);
        }
    }



    /**
     * @param User $user
     * @return JsonResponse
     *
     * @permission UserController::show
     * @permission_desc Afficher les détails d'un utilisateur
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'user' => $user->load([
                'roles:id,name',
                'establishments:uuid,code,name'
            ]),
        ]);
    }

    /**
     * @param UserRequest $request
     * @param User $user
     * @return JsonResponse
     *
     * @permission UserController::update
     * @permission_desc Modifier un utilisateur
     */
    public function update(UserRequest $request, User $user,LicenseService $licenseService): JsonResponse
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $establishmentUuidHeader = $request->header('X-Establishment-Uuid');

        DB::beginTransaction();
        try {
            $data = $request->except('password');
            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }
            $data['updated_by'] = auth()->id();
            if ($establishmentUuidHeader && empty($data['establishment_uuid'])) {
                $data['establishment_uuid'] = $establishmentUuidHeader;
            }

            $user->update($data);

            if ($request->has('roles')) {
                $user->roles()->detach();
                foreach ($request->roles as $role) {
                    $user->roles()->attach($role, [
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id()
                    ]);
                }
            }

            if ($request->has('establishments') || $request->filled('establishments')) {
                $establishmentsInput = $request->input('establishments', []);
                $newEstablishmentsData = [];
                $defaultEstablishmentUuid = null;

                if (is_array($establishmentsInput)) {
                    foreach ($establishmentsInput as $estData) {
                        $estUuid = is_array($estData)
                            ? ($estData['uuid'] ?? $estData['establishment_uuid'] ?? null)
                            : $estData;

                        if ($estUuid) {
                            $isDefault = is_array($estData) ? filter_var($estData['default'] ?? false, FILTER_VALIDATE_BOOLEAN) : false;
                            $newEstablishmentsData[$estUuid] = ['default' => $isDefault];

                            if ($isDefault) {
                                $defaultEstablishmentUuid = $estUuid;
                            }
                        }
                    }
                }

                $existingEstablishments = \App\Models\UserEstablishment::where('user_id', $user->id)->get();
                $existingUuids = $existingEstablishments->pluck('establishment_uuid')->toArray();

                $incomingUuids = array_keys($newEstablishmentsData);

                $uuidsToRemove = array_diff($existingUuids, $incomingUuids);
                if (!empty($uuidsToRemove)) {
                    \App\Models\UserEstablishment::where('user_id', $user->id)
                        ->whereIn('establishment_uuid', $uuidsToRemove)
                        ->delete();
                }

                foreach ($newEstablishmentsData as $uuid => $attributes) {
                    \App\Models\UserEstablishment::updateOrCreate(
                        [
                            'user_id'            => $user->id,
                            'establishment_uuid' => $uuid,
                        ],
                        [
                            'default'    => $attributes['default'],
                            'updated_by' => auth()->id(),
                            'created_by' => \App\Models\UserEstablishment::where('user_id', $user->id)
                                    ->where('establishment_uuid', $uuid)
                                    ->value('created_by') ?? auth()->id(),
                        ]
                    );
                }

                if ($defaultEstablishmentUuid) {
                    $user->update(['establishment_uuid' => $defaultEstablishmentUuid]);
                } elseif ($establishmentUuidHeader && in_array($establishmentUuidHeader, $incomingUuids)) {
                    $user->update(['establishment_uuid' => $establishmentUuidHeader]);
                } elseif (!empty($incomingUuids)) {
                    $user->update(['establishment_uuid' => $incomingUuids[0]]);
                }
            } elseif ($establishmentUuidHeader) {
                $user->update(['establishment_uuid' => $establishmentUuidHeader]);
            }

            DB::commit();

            return response()->json([
                'message' => __("L'utilisateur a été mis à jour avec succès !"),
            ], Response::HTTP_OK);

        } catch (\Exception $th) {
            DB::rollBack();
            Log::error($th->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => "Une erreur est survenue lors de la mise à jour de l'utilisateur.",
                'error'   => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     *
     * @permission UserController::changeStatus
     * @permission_desc Activer/Désactiver utilisateur
     */
    public function changeStatus(Request $request, User $user): JsonResponse
    {
        $request->validate(['status' => ['required', 'boolean']]);

        $user->update(['status' => $request->status]);

        return response()->json([
            'message' => __('L\'utilisateur a été mis à jour avec succès !'),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        // TODO: A faire plus tard
    }
}
