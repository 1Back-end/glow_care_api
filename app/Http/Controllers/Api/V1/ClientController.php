<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ClientsExport;
use App\Exports\ProductsExport;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\User;
use App\Models\UserEstablishment;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @permission_category Gestion des clients
 */
class ClientController extends Controller
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
     * @permission ClientController::index
     * @permission_desc Afficher la liste des clients
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $currentUser = auth()->user();

        $query = Client::with(['creator', 'updater'])
            ->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser) {
                $userEstablishmentUuids = UserEstablishment::where('user_id', $currentUser->id)
                    ->pluck('establishment_uuid');
                $q->whereIn('establishment_uuid', $userEstablishmentUuids);
            })
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

        $clients = $query->orderBy('full_name', 'asc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $clients->items(),
            'current_page' => $clients->currentPage(),
            'last_page'    => $clients->lastPage(),
            'total'        => $clients->total(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission ClientController::store
     * @permission_desc Créer un client
     */
    public function store(Request $request, LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $validatedData = $request->validate([
            'full_name'    => 'required|string',
            'phone'        => 'required|string',
            'second_phone' => 'nullable|string',
            'is_watsapp'   => 'nullable|boolean',
            'birth_date'   => 'nullable|date',
            'email'        => 'nullable|email',
            'address'      => 'nullable|string',
            'notes'        => 'nullable|string',
            'is_active'    => 'nullable|boolean',
        ]);

        if (Client::where('establishment_uuid', $establishmentUuid)
            ->where('phone', $validatedData['phone'])
            ->exists()) {
            return response()->json([
                'status'  => false,
                'message' => 'Un client avec ce numéro de téléphone existe déjà dans cet établissement.',
            ], 422);
        }

        $cleanNewName = strtolower(trim(preg_replace('/\s+/', ' ', $validatedData['full_name'])));
        $newWords = explode(' ', $cleanNewName);
        sort($newWords);

        $existingClients = Client::where('establishment_uuid', $establishmentUuid)->get();

        foreach ($existingClients as $client) {
            $cleanExistingName = strtolower(trim(preg_replace('/\s+/', ' ', $client->full_name)));
            $existingWords = explode(' ', $cleanExistingName);
            sort($existingWords);

            if ($newWords === $existingWords) {
                return response()->json([
                    'status'  => false,
                    'message' => "Doublon potentiel détecté : un client enregistré sous le nom de $client->full_name possède les mêmes identifiants nominatifs dans cet établissement.",
                ], 422);
            }
        }

        $phone = $validatedData['phone'];
        $email = $validatedData['email'] ?? null;
        $password = \Illuminate\Support\Str::random(8);
        $login = $phone;

        $user = \App\Models\User::create([
            'login'                 => $login,
            'email'                 => $email,
            'password'              => \Illuminate\Support\Facades\Hash::make($password),
            'nom_utilisateur'       => $validatedData['full_name'],
            'created_by'            => $auth?->id,
            'updated_by'            => $auth?->id,
        ]);

        \App\Models\UserEstablishment::firstOrCreate([
            'user_id'            => $user->id,
            'establishment_uuid' => $establishmentUuid,
        ], [
            'default'            => true,
        ]);

        $validatedData['establishment_uuid'] = $establishmentUuid;
        $validatedData['user_id'] = $user?->id;
        $validatedData['created_by'] = $auth?->id;

        $client = Client::create($validatedData);

        $establishment = Establishment::where('uuid', $establishmentUuid)->first();

        if ($establishment && $establishment->user) {
            if ($establishment->user->id !== $auth->id) {
                $establishment->user->notify(new \App\Notifications\ClientActivityNotification(
                    "Nouveau client enregistré : {$client->full_name} a été ajouté par {$auth->nom_utilisateur} dans votre établissement.",
                    $client
                ));
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Client créé avec succès',
            'data'    => $client->load('user'),
            'user'    => $user,
        ], 201);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission ClientController::show
     * @permission_desc Afficher les détails d'un client
     */
    public function show(Client $client)
    {
        return response()->json([
            'status' => true,
            'data'   => $client
        ], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission ClientController::update
     * @permission_desc Mettre à jour un client
     */
    public function update(Request $request, LicenseService $licenseService, $uuid)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $client = Client::where('establishment_uuid', $establishmentUuid)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $validatedData = $request->validate([
            'full_name'    => 'required|string',
            'phone'        => 'required|string',
            'second_phone' => 'nullable|string',
            'is_watsapp'   => 'nullable|boolean',
            'birth_date'   => 'nullable|date',
            'email'        => 'nullable|email',
            'address'      => 'nullable|string',
            'notes'        => 'nullable|string',
            'is_active'    => 'nullable|boolean',
        ]);

        if (Client::where('establishment_uuid', $establishmentUuid)
            ->where('phone', $validatedData['phone'])
            ->where('uuid', '!=', $client->uuid)
            ->exists()) {
            return response()->json([
                'status'  => false,
                'message' => 'Un client avec ce numéro de téléphone existe déjà dans cet établissement.',
            ], 422);
        }

        $cleanNewName = strtolower(trim(preg_replace('/\s+/', ' ', $validatedData['full_name'])));
        $newWords = explode(' ', $cleanNewName);
        sort($newWords);

        $existingClients = Client::where('establishment_uuid', $establishmentUuid)
            ->where('uuid', '!=', $client->uuid)
            ->get();

        foreach ($existingClients as $existingClient) {
            $cleanExistingName = strtolower(trim(preg_replace('/\s+/', ' ', $existingClient->full_name)));
            $existingWords = explode(' ', $cleanExistingName);
            sort($existingWords);

            if ($newWords === $existingWords) {
                return response()->json([
                    'status'  => false,
                    'message' => "Doublon potentiel détecté : un client enregistré sous le nom de {$existingClient->full_name} possède les mêmes identifiants nominatifs dans cet établissement.",
                ], 422);
            }
        }

        $phone = $validatedData['phone'];
        $email = $validatedData['email'] ?? null;
        $login = $phone;

        if ($client->user) {
            $client->user->update([
                'login'           => $login,
                'email'           => $email,
                'nom_utilisateur' => $validatedData['full_name'],
                'updated_by'      => $auth->id,
            ]);
        } else {
            $password = \Illuminate\Support\Str::random(8);

            $user = \App\Models\User::create([
                'login'           => $login,
                'email'           => $email,
                'password'        => \Illuminate\Support\Facades\Hash::make($password),
                'nom_utilisateur' => $validatedData['full_name'],
                'created_by'      => $auth->id,
                'updated_by'      => $auth->id,
            ]);

            $validatedData['user_id'] = $user->id;

            \App\Models\UserEstablishment::firstOrCreate([
                'user_id'            => $user->id,
                'establishment_uuid' => $establishmentUuid,
            ], [
                'default'            => true,
            ]);
        }

        $validatedData['establishment_uuid'] = $establishmentUuid;
        $validatedData['updated_by'] = $auth->id;
        $client->update($validatedData);

        $establishment = Establishment::where('uuid', $establishmentUuid)->first();

        if ($establishment && $establishment->user) {
            if ($establishment->user->id !== $auth->id) {
                $establishment->user->notify(new \App\Notifications\ClientActivityNotification(
                    "Mise à jour client : Les informations de {$client->full_name} ont été modifiées par {$auth->nom_utilisateur} dans votre établissement.",
                    $client
                ));
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Client mis à jour avec succès',
            'data'    => $client->load('user')
        ], 200);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission ClientController::updateStatus
     * @permission_desc Activer/Désactiver un client
     */
    public function updateStatus(Request $request, string $uuid,LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        $client = Client::where('uuid', $uuid)->firstOrFail();

        $client->is_active = $request->is_active;
        $client->updated_by = $auth->id;
        $client->save();

        return response()->json([
            'status'  => true,
            'message' => 'Statut du client mis à jour avec succès',
            'data'    => $client
        ], 200);
    }
    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission ClientController::export
     * @permission_desc Exporter la liste des clients en Excel
     */
    public function export(Request $request,LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }
        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);
        $establishmentUuid = $request->header('X-Establishment-Uuid');

        if (empty($establishmentUuid)) {
            return response()->json([
                'status'  => false,
                'message' => 'L’en-tête X-Establishment-Uuid est manquant.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $fileName = mb_strtoupper('LISTE-DES-CLIENTS_') . Carbon::now()->format('Y-m-d_H-i-s') . '.xlsx';

            Excel::store(new ClientsExport($establishmentUuid), $fileName, 'exportclients');

            return response()->json([
                "success" => true,
                "message" => "Exportation des données effectuée avec succès",
                "filename" => $fileName,
                "url" => Storage::disk('exportclients')->url($fileName)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Une erreur est survenue lors de l'exportation des données.",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @return JsonResponse
     *
     * @permission ClientController::printFidelityCard
     * @permission_desc Imprimer une carte de fidélité pour un client
     */
    public function printFidelityCard(string $uuid, Request $request)
    {
        $client = Client::where('uuid', $uuid)->firstOrFail();

        $oldFidelityCard = $client->fidelityCard()->latest()->first();
        if ($oldFidelityCard) {
            Storage::disk('public')->delete($oldFidelityCard->path);
            $oldFidelityCard->delete();
        }

        $establishment = $client->establishment;
        $media = $establishment?->medias()->where('name', 'logo')->first();
        $validityDays = intval($request->input('validity', 365));

        $data = [
            'validity' => $validityDays,
            'client' => $client,
            'establishment' => $establishment,
            'logo' => ($media && file_exists(storage_path('app/public/' . $media->path . '/' . $media->filename)))
                ? base64_encode(file_get_contents(storage_path('app/public/' . $media->path . '/' . $media->filename)))
                : ($establishment?->public_logo ? @base64_encode(@file_get_contents(public_path($establishment->public_logo))) : ''),
            'mimetype' => $media ? $media->mimetype : 'image/png',
        ];

        $identifier = $client->code ?? $client->uuid;
        $folder = 'storage/fidelity-card';
        $fileName = $identifier . '.pdf';
        $fullPath = $folder . '/' . $fileName;

        try {
            save_browser_shot_pdf(
                view: 'pdfs.fidelity-cart',
                data: $data,
                folderPath: $folder,
                path: $fullPath,
                format: 'a6',
                direction: 'landscape'
            );
        } catch (CouldNotTakeBrowsershot | Throwable $e) {
            Log::error($e->getMessage());

            return \response()->json([
                'message' => __("Une erreur inattendue est survenue.")
            ], 400);
        }

        $path = 'fidelity-card/' . $fileName;

        $client->fidelityCard()->create([
            'name' => "fidelityCard",
            'disk' => 'public',
            'path' => $path,
            'filename' => $fileName,
            'mimetype' => 'pdf',
            'extension' => 'pdf',
            'validity' => $validityDays,
            'created_by' => auth()->id(),
        ]);

        return \response()->json([
            'url' => Storage::disk('public')->url($path)
        ]);
    }
}
