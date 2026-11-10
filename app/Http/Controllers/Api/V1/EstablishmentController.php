<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivationStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\EstablishmentActivation;
use App\Models\EstablishmentAvailabilityDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * @permission_category Gestion des établissements
 * @is_super_admin true
 */

class EstablishmentController extends Controller
{
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission EstablishmentController::index
     * @permission_desc Afficher la liste des établissements
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $establishmentUuid = $request->header('X-Establishment-Uuid');
        $currentUser = auth()->user();

        $query = Establishment::with(['creator', 'updater', 'availabilityDays','owner','license','activeActivation'])
            ->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser, $establishmentUuid) {
                $q->whereHas('users', function ($userQuery) use ($currentUser) {
                    $userQuery->where('users.id', $currentUser->id);
                });

                if ($establishmentUuid) {
                    $q->where('uuid', $establishmentUuid);
                }
            })
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('activity_sector', 'like', "%{$search}%");
            });
        }

        $establishments = $query->orderBy('name', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $establishments->items(),
            'current_page' => $establishments->currentPage(),
            'last_page'    => $establishments->lastPage(),
            'total'        => $establishments->total(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission EstablishmentController::store
     * @permission_desc Créer un établissement
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                        => 'required|string',
            'phone'                       => 'required|string',
            'second_phone'                => 'nullable|string',
            'email'                       => 'nullable|email|unique:establishments,email',
            'address'                     => 'required|string',
            'city'                        => 'required|string',
            'reference'                   => 'nullable|string',
            'activity_sector'             => 'required|string',
            'owner_uuid'                  => 'required|exists:owners,uuid',
            'license_uuid'                 => 'nullable|exists:licenses,uuid',
            'logo'                        => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'cover_image'                 => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'availability_days'           => 'nullable|string',
            'availability_days.*.day_of_week'  => 'required_with:availability_days|string',
            'availability_days.*.opening_time' => 'nullable|date_format:H:i',
            'availability_days.*.closing_time' => 'nullable|date_format:H:i',
            'availability_days.*.is_open'      => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $establishment = Establishment::create([
                'name'            => $validated['name'],
                'phone'           => $validated['phone'],
                'second_phone'    => $validated['second_phone'] ?? null,
                'email'           => $validated['email'] ?? null,
                'address'         => $validated['address'],
                'city'            => $validated['city'],
                'activity_sector' => $validated['activity_sector'],
                'reference'       => $validated['reference'] ?? null,
                'owner_uuid'      => $validated['owner_uuid'],
                'license_uuid'     => $validated['license_uuid'],
                'is_active'       => $request->boolean('is_active', true),
                'created_by'      => auth()->id(),
                'updated_by'      => auth()->id(),
            ]);

            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $filename = time() . '_logo_' . $file->getClientOriginalName();
                $path = $file->store('establishments/logos', 'public');

                $establishment->update(['logo' => $path]);

                $establishment->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            if ($request->hasFile('cover_image')) {
                $file = $request->file('cover_image');
                $filename = time() . '_cover_' . $file->getClientOriginalName();
                $path = $file->store('establishments/covers', 'public');

                $establishment->update(['cover_image' => $path]);

                $establishment->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            if ($request->filled('availability_days')) {
                $availabilityData = json_decode($request->input('availability_days'), true);

                if (is_array($availabilityData)) {
                    foreach ($availabilityData as $dayData) {
                        if (!empty($dayData['day_of_week'])) {
                            EstablishmentAvailabilityDay::create([
                                'establishment_uuid' => $establishment->uuid,
                                'day_of_week'        => $dayData['day_of_week'],
                                'opening_time'       => !empty($dayData['opening_time']) ? $dayData['opening_time'] : null,
                                'closing_time'       => !empty($dayData['closing_time']) ? $dayData['closing_time'] : null,
                                'is_open'            => $dayData['is_open'] ?? true,
                                'created_by'         => auth()->id(),
                                'updated_by'         => auth()->id(),
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Établissement créé avec succès !',
                'data'    => $establishment->load(['creator', 'updater', 'availabilityDays', 'medias', 'owner']),
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de la création de l’établissement.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission EstablishmentController::update
     * @permission_desc Mettre à jour établissement
     */
    public function update(Request $request, $uuid)
    {
        $establishment = Establishment::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name'                        => 'required|string',
            'phone'                       => 'required|string',
            'second_phone'                => 'nullable|string',
            'email'                       => 'nullable|email|unique:establishments,email,' . $establishment->uuid . ',uuid',
            'address'                     => 'required|string',
            'city'                        => 'required|string',
            'activity_sector'             => 'required|string',
            'reference'                   => 'nullable|string',
            'owner_uuid'                  => 'required|exists:owners,uuid',
            'license_uuid'                => 'nullable|exists:licenses,uuid',
            'logo'                        => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'cover_image'                 => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
            'availability_days'           => 'nullable|string',
            'availability_days.*.day_of_week'  => 'required_with:availability_days|string',
            'availability_days.*.opening_time' => 'nullable|date_format:H:i',
            'availability_days.*.closing_time' => 'nullable|date_format:H:i',
            'availability_days.*.is_open'      => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $newLicenseUuid = $validated['license_uuid'] ?? null;

            $establishment->update([
                'name'            => $validated['name'],
                'phone'           => $validated['phone'],
                'second_phone'    => $validated['second_phone'] ?? null,
                'email'           => $validated['email'] ?? null,
                'address'         => $validated['address'],
                'city'            => $validated['city'],
                'activity_sector' => $validated['activity_sector'],
                'reference'       => $validated['reference'] ?? null,
                'owner_uuid'      => $validated['owner_uuid'],
                'license_uuid'    => $newLicenseUuid,
                'is_active'       => $request->boolean('is_active', $establishment->is_active),
                'updated_by'      => auth()->id(),
            ]);

            if ($request->hasFile('logo')) {
                if ($establishment->logo && \Illuminate\Support\Facades\Storage::disk('public')->exists($establishment->logo)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($establishment->logo);
                }

                $file = $request->file('logo');
                $filename = time() . '_logo_' . $file->getClientOriginalName();
                $path = $file->store('establishments/logos', 'public');

                $establishment->update(['logo' => $path]);

                $establishment->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }

            if ($request->hasFile('cover_image')) {
                if ($establishment->cover_image && \Illuminate\Support\Facades\Storage::disk('public')->exists($establishment->cover_image)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($establishment->cover_image);
                }

                $file = $request->file('cover_image');
                $filename = time() . '_cover_' . $file->getClientOriginalName();
                $path = $file->store('establishments/covers', 'public');

                $establishment->update(['cover_image' => $path]);

                $establishment->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }


            if ($request->filled('availability_days')) {
                $availabilityData = json_decode($request->input('availability_days'), true);

                if (is_array($availabilityData)) {
                    foreach ($availabilityData as $dayData) {
                        if (!empty($dayData['day_of_week'])) {
                            EstablishmentAvailabilityDay::updateOrCreate(
                                [
                                    'establishment_uuid' => $establishment->uuid,
                                    'day_of_week'        => $dayData['day_of_week'],
                                ],
                                [
                                    'opening_time'       => !empty($dayData['opening_time']) ? $dayData['opening_time'] : null,
                                    'closing_time'       => !empty($dayData['closing_time']) ? $dayData['closing_time'] : null,
                                    'is_open'            => $dayData['is_open'] ?? true,
                                ]
                            );
                        }
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Établissement mis à jour avec succès !',
                'data'    => $establishment->load(['creator', 'updater', 'availabilityDays', 'medias', 'owner', 'license', 'activeActivation']),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de la mise à jour de l’établissement.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission EstablishmentController::updateStatus
     * @permission_desc Activer/Désactiver un établissement
     */
    public function updateStatus(Request $request, $uuid)
    {
        $establishment = Establishment::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        try {
            $establishment->update([
                'is_active'  => $validated['is_active'],
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'status'  => true,
                'message' => 'Statut de l’établissement mis à jour avec succès !',
                'data'    => $establishment->load(['creator', 'updater', 'availabilityDays', 'medias']),
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Erreur lors de la mise à jour du statut.',
                'error'   => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission EstablishmentController::show
     * @permission_desc Afficher les détails d'un établissement
     */
    public function show($uuid)
    {
        $establishment = Establishment::with(['creator', 'updater', 'availabilityDays', 'medias','owner','license'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'status'  => true,
            'message' => 'Établissement récupéré avec succès !',
            'data'    => $establishment,
        ], Response::HTTP_OK);
    }
}
