<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Owner;
use App\Models\User;
use App\Models\UserEstablishment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * @permission_category Gestion des propriétaires
 * @is_super_admin true
 */

class OwnerController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission OwnerController::index
     * @permission_desc Afficher la liste des propriétaires
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Owner::with(['creator', 'updater', 'establishments'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('second_phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $owners = $query->orderBy('full_name', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $owners->items(),
            'current_page' => $owners->currentPage(),
            'last_page'    => $owners->lastPage(),
            'total'        => $owners->total(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission OwnerController::store
     * @permission_desc Créer un propriétaire
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name'       => 'required|string|max:255',
            'phone'           => 'required|string|max:50',
            'second_phone'    => 'nullable|string|max:50',
            'is_whatsapp'     => 'nullable|boolean',
            'email'           => 'nullable|email|unique:owners,email|unique:users,email',
            'address'         => 'nullable|string|max:255',
            'description'     => 'nullable|string',
            'is_active'       => 'nullable|boolean',
            'cni_file'        => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        try {
            DB::beginTransaction();

            $email = $validated['email'] ?? null;
            $password = \Illuminate\Support\Str::random(8);
            $login = $email ?? $validated['phone'];

            $user = User::create([
                'login'                 => $login,
                'email'                 => $email,
                'password'              => Hash::make($password),
                'nom_utilisateur'       => $validated['full_name'],
                'created_by'            => auth()->id(),
                'updated_by'            => auth()->id(),
            ]);

            $owner = Owner::create([
                'full_name'    => $validated['full_name'],
                'phone'        => $validated['phone'],
                'second_phone' => $validated['second_phone'] ?? null,
                'is_whatsapp'  => $request->boolean('is_whatsapp', false),
                'email'        => $email,
                'address'      => $validated['address'] ?? null,
                'description'  => $validated['description'] ?? null,
                'is_active'    => $request->boolean('is_active', true),
                'user_id'      => $user->id,
                'created_by'   => auth()->id(),
                'updated_by'   => auth()->id(),
            ]);

            if ($request->hasFile('cni_file')) {
                $file = $request->file('cni_file');
                $filename = time() . '_cover_' . $file->getClientOriginalName();
                $path = $file->store('owners/cni_file', 'public');

                $owner->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status'             => true,
                'message'            => 'Propriétaire et compte utilisateur créés avec succès !',
                'generated_password' => $password,
                'data'               => $owner->load(['user', 'creator', 'updater', 'establishments']),
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de la création du propriétaire et de son compte.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission OwnerController::update
     * @permission_desc Mettre à jour un propriétaire
     */
    public function update(Request $request, $uuid)
    {
        $owner = Owner::with(['user', 'medias'])->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'full_name'       => 'sometimes|required|string|max:255',
            'phone'           => 'sometimes|required|string|max:50',
            'second_phone'    => 'nullable|string|max:50',
            'is_whatsapp'     => 'nullable|boolean',
            'email'           => 'nullable|email|unique:owners,email,' . $owner->uuid . ',uuid|unique:users,email,' . optional($owner->user)->id,
            'address'         => 'nullable|string|max:255',
            'description'     => 'nullable|string',
            'is_active'       => 'nullable|boolean',
            'cni_file'        => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
        ]);

        try {
            DB::beginTransaction();

            if ($owner->user) {
                $owner->user->update([
                    'email'           => $validated['email'] ?? $owner->user->email,
                    'nom_utilisateur' => $validated['full_name'] ?? $owner->user->nom_utilisateur,
                    'updated_by'      => auth()->id(),
                ]);
            }

            $owner->update([
                'full_name'    => $validated['full_name'] ?? $owner->full_name,
                'phone'        => $validated['phone'] ?? $owner->phone,
                'second_phone' => array_key_exists('second_phone', $validated) ? $validated['second_phone'] : $owner->second_phone,
                'is_whatsapp'  => $request->has('is_whatsapp') ? $request->boolean('is_whatsapp') : $owner->is_whatsapp,
                'email'        => $validated['email'] ?? $owner->email,
                'address'      => array_key_exists('address', $validated) ? $validated['address'] : $owner->address,
                'description'  => array_key_exists('description', $validated) ? $validated['description'] : $owner->description,
                'is_active'    => $request->has('is_active') ? $request->boolean('is_active') : $owner->is_active,
                'updated_by'   => auth()->id(),
            ]);

            if ($request->hasFile('cni_file')) {
                $file = $request->file('cni_file');
                $filename = time() . '_cover_' . $file->getClientOriginalName();
                $path = $file->store('owners/cni_file', 'public');

                $owner->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Propriétaire mis à jour avec succès !',
                'data'    => $owner->load(['user', 'creator', 'updater', 'establishments', 'medias']),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de la mise à jour du propriétaire.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission OwnerController::show
     * @permission_desc Afficher les détails d'un propriétaire
     */
    public function show($uuid)
    {
        $owner = Owner::with(['user', 'creator', 'updater', 'establishments', 'medias'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'status'  => true,
            'message' => 'Propriétaire récupéré avec succès.',
            'data'    => $owner,
        ], Response::HTTP_OK);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission OwnerController::updateStatus
     * @permission_desc Activer/Désactiver un propriétaire
     */
    public function updateStatus(Request $request, $uuid)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $owner = Owner::with('user')->where('uuid', $uuid)->firstOrFail();

        try {
            DB::beginTransaction();

            $owner->update([
                'is_active'  => $validated['is_active'],
                'updated_by' => auth()->id(),
            ]);

            if ($owner->user) {
                $owner->user->update([
                    'status'     => $validated['is_active'] ? 1 : 0,
                    'updated_by' => auth()->id(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Statut du propriétaire mis à jour avec succès.',
                'data'    => $owner->load(['user', 'creator', 'updater', 'establishments', 'medias']),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de la mise à jour du statut.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getByUser(Request $request)
    {
        $user = auth()->user();

        $userEstablishments = UserEstablishment::getByUser($user->id);

        return response()->json([
            'status'  => true,
            'message' => 'Établissements de l’utilisateur récupérés avec succès via UserEstablishment !',
            'data'    => $userEstablishments,
        ], Response::HTTP_OK);
    }
}
