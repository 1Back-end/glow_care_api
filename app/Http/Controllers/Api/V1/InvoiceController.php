<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentOriginEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StateFacture;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicesFacture;
use App\Models\UserEstablishment;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * @permission_category Facturations des prestations
 */

class InvoiceController extends Controller
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
     * @permission InvoiceController::index
     * @permission_desc Afficher la liste des facturations
     */
    public function index(Request $request)
    {
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);
        $currentUser = auth()->user();

        $query = Invoice::with(['client:uuid,code,full_name', 'creator:id,nom_utilisateur', 'updater:id,nom_utilisateur', 'items.prestation','facture'])
            ->when(!$currentUser?->is_super_admin, function ($q) use ($currentUser) {
                $userEstablishmentUuids = UserEstablishment::where('user_id', $currentUser->id)
                    ->pluck('establishment_uuid');
                $q->whereIn('establishment_uuid', $userEstablishmentUuids);
            })
            ->when($request->has('regulated'), function ($query) use ($request) {
                $query->where('regulated', $request->input('regulated'));
            })
            ->when($request->has('state'), function ($query) use ($request) {
                $query->where('state', filter_var($request->input('state'), FILTER_VALIDATE_BOOLEAN));
            });

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($clientQuery) use ($search) {
                        $clientQuery->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $invoices = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'status'       => true,
            'data'         => $invoices->items(),
            'current_page' => $invoices->currentPage(),
            'last_page'    => $invoices->lastPage(),
            'total'        => $invoices->total(),
        ]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission InvoiceController::store
     * @permission_desc Créer une facture
     */
    public function store(Request $request, LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid');

        $validated = $request->validate([
            'client_uuid'             => 'required|exists:clients,uuid',
            'programmation_date'      => 'nullable|date',
            'remise'                  => 'nullable|numeric|min:0',
            'appointment_origin'      => ['nullable', Rule::enum(AppointmentOriginEnum::class)],
            'regulated'               => ['nullable', Rule::enum(StateFacture::class)],
            'state'                   => 'nullable|boolean',
            'items'                   => 'required|array|min:1',
            'items.*.prestation_uuid' => 'required|exists:prestations,uuid',
            'items.*.price'           => 'required|numeric|min:0',
            'items.*.quantity'        => 'required|integer|min:1',
            'items.*.remise'          => 'nullable|numeric|min:0',
        ]);

        $user = auth()->user();

        try {
            return DB::transaction(function () use ($validated, $user, $establishmentUuid) {

                $stateFacture = $validated['regulated'] ?? StateFacture::CREATE->value;

                // Correction : On retire 'type' d'ici pour éviter le conflit avec PrestationCategory
                $invoice = Invoice::create([
                    'establishment_uuid' => $establishmentUuid,
                    'client_uuid'        => $validated['client_uuid'],
                    'regulated'          => $stateFacture,
                    'programmation_date' => $validated['programmation_date'] ?? now(),
                    'state'              => $validated['state'] ?? false,
                    'appointment_origin' => $validated['appointment_origin'] ?? AppointmentOriginEnum::WALK_IN->value,
                    'created_by'         => $user->id,
                ]);

                foreach ($validated['items'] as $index => $itemData) {
                    InvoiceItem::create([
                        'invoices_uuid'      => $invoice->uuid,
                        'establishment_uuid' => $establishmentUuid,
                        'prestation_uuid'    => $itemData['prestation_uuid'],
                        'quantity'           => $itemData['quantity'],
                        'price'              => $itemData['price'],
                        'remise'             => $itemData['remise'] ?? 0,
                        'sequence_number'    => str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                        'created_by'         => $user->id,
                    ]);
                }

                // Récupération sécurisée du type de la première prestation pour la facture
                $firstPrestationType = optional($invoice->items()->with('prestation')->first()?->prestation)->type;

                InvoicesFacture::create([
                    'invoices_uuid'      => $invoice->uuid,
                    'establishment_uuid' => $establishmentUuid,
                    'type'               => $invoice->type ?? $firstPrestationType,
                    'state_facture'      => $stateFacture,
                    'amount'             => $invoice->total_amount,
                    'date_fac'           => now(),
                    'created_by'         => $user->id,
                ]);

                $invoice->load(['client', 'establishment', 'items.prestation', 'facture']);

                $filename = time() . '_qrcode_' . $invoice->uuid . '.svg';
                $path = 'invoices/qrcodes/' . $filename;

                $qrPayload = [
                    'invoice_code'       => $invoice->code,
                    'uuid'               => $invoice->uuid,
                    'establishment_name' => $invoice->establishment->name ?? null,
                    'client_name'        => $invoice->client->full_name ?? null,
                    'total_amount'       => $invoice->total_to_pay ?? $invoice->total_amount,
                    'date'               => $invoice->programmation_date ?? now()->toIso8601String(),
                ];

                $qrCodeSvg = QrCode::format('svg')
                    ->size(300)
                    ->errorCorrection('H')
                    ->generate(json_encode($qrPayload));

                Storage::disk('public')->put($path, $qrCodeSvg);

                $invoice->update(['qr_code' => $path]);

                $invoice->medias()->create([
                    'name'      => $filename,
                    'disk'      => 'public',
                    'path'      => $path,
                    'filename'  => $filename,
                    'mimetype'  => 'image/svg+xml',
                    'extension' => 'svg',
                ]);

                $invoice->load('medias');

                return response()->json([
                    'success' => true,
                    'message' => 'Facturation enregistrée avec succès.',
                    'data'    => $invoice
                ], 201);
            });

        } catch (\Exception $e) {
            Log::error("Erreur lors de la création de la facture : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'enregistrement de la facture.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission InvoiceController::update
     * @permission_desc Mettre à jour une facture
     */
    public function update(Request $request, $uuid, LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $validated = $request->validate([
            'client_uuid'             => 'required|exists:clients,uuid',
            'programmation_date'      => 'nullable|date',
            'remise'                  => 'nullable|numeric|min:0',
            'appointment_origin'      => ['nullable', Rule::enum(AppointmentOriginEnum::class)],
            'regulated'               => ['nullable', Rule::enum(StateFacture::class)],
            'state'                   => 'nullable|boolean',
            'items'                   => 'required|array|min:1',
            'items.*.prestation_uuid' => 'required|exists:prestations,uuid',
            'items.*.price'           => 'required|numeric|min:0',
            'items.*.quantity'        => 'required|integer|min:1',
            'items.*.remise'          => 'nullable|numeric|min:0',
        ]);

        $user = auth()->user();
        $establishmentUuid = $request->header('X-Establishment-Uuid') ?? $user->establishment_uuid;

        try {
            return DB::transaction(function () use ($validated, $user, $establishmentUuid, $uuid) {

                $invoice = Invoice::where('uuid', $uuid)
                    ->where('establishment_uuid', $establishmentUuid)
                    ->firstOrFail();

                $invoice->update([
                    'client_uuid'        => $validated['client_uuid'],
                    'regulated'          => $validated['regulated'] ?? $invoice->regulated,
                    'programmation_date' => $validated['programmation_date'] ?? $invoice->programmation_date,
                    'state'              => $validated['state'] ?? $invoice->state,
                    'appointment_origin' => $validated['appointment_origin'] ?? $invoice->appointment_origin,
                    'updated_by'         => $user->id,
                ]);

                $invoice->items()->delete();

                foreach ($validated['items'] as $index => $itemData) {
                    InvoiceItem::create([
                        'invoices_uuid'      => $invoice->uuid,
                        'establishment_uuid' => $establishmentUuid,
                        'prestation_uuid'    => $itemData['prestation_uuid'],
                        'quantity'           => $itemData['quantity'],
                        'price'              => $itemData['price'],
                        'remise'             => $itemData['remise'] ?? 0,
                        'sequence_number'    => str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                        'created_by'         => $invoice->created_by ?? $user->id,
                        'updated_by'         => $user->id,
                    ]);
                }

                $invoice->unsetRelation('items');

                $firstPrestationType = optional($invoice->items()->with('prestation')->first()?->prestation)->type;

                $facture = InvoicesFacture::where('invoices_uuid', $invoice->uuid)->first();

                if ($facture) {
                    $facture->update([
                        'type'          => $invoice->type ?? $firstPrestationType,
                        'state_facture' => $invoice->regulated,
                        'amount'        => $invoice->total_amount,
                        'updated_by'    => $user->id,
                    ]);
                } else {
                    InvoicesFacture::create([
                        'invoices_uuid'      => $invoice->uuid,
                        'establishment_uuid' => $establishmentUuid,
                        'type'               => $invoice->type ?? $firstPrestationType,
                        'state_facture'      => $invoice->regulated,
                        'amount'             => $invoice->total_amount,
                        'date_fac'           => now(),
                        'created_by'         => $user->id,
                    ]);
                }

                if (empty($invoice->qr_code) || !Storage::disk('public')->exists($invoice->qr_code)) {
                    $invoice->load(['client', 'establishment']);

                    $filename = time() . '_qrcode_' . $invoice->uuid . '.svg';
                    $path = 'invoices/qrcodes/' . $filename;

                    $qrPayload = [
                        'invoice_code'       => $invoice->code,
                        'uuid'               => $invoice->uuid,
                        'establishment_name' => $invoice->establishment->name ?? null,
                        'client_name'        => $invoice->client->full_name ?? null,
                        'total_amount'       => $invoice->total_to_pay ?? $invoice->total_amount,
                        'date'               => $invoice->programmation_date ?? now()->toIso8601String(),
                    ];

                    $qrCodeSvg = QrCode::format('svg')
                        ->size(300)
                        ->errorCorrection('H')
                        ->generate(json_encode($qrPayload));

                    Storage::disk('public')->put($path, $qrCodeSvg);

                    $invoice->update(['qr_code' => $path]);

                    $invoice->medias()->create([
                        'name'      => $filename,
                        'disk'      => 'public',
                        'path'      => $path,
                        'filename'  => $filename,
                        'mimetype'  => 'image/svg+xml',
                        'extension' => 'svg',
                    ]);
                }

                $invoice->load(['client', 'items.prestation', 'facture', 'medias']);

                return response()->json([
                    'success' => true,
                    'message' => 'Facturation mise à jour avec succès.',
                    'data'    => $invoice
                ], 200);
            });

        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour de la facture ($uuid) : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de la mise à jour de la facture.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission InvoiceController::show
     * @permission_desc Afficher les détails d'une facture
     */
    public function show(Request $request, $uuid)
    {
        try {
            $invoice = Invoice::where('uuid', $uuid)
                ->with(['client', 'creator', 'updater', 'items.prestation','establishment','facture'])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data'    => $invoice
            ], 200);

        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération de la facture ($uuid) : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => "Facture introuvable ou erreur lors du chargement.",
                'error'   => $e->getMessage()
            ], 404);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission InvoiceController::cancel
     * @permission_desc Annuler une facture
     */
    public function cancel(Request $request, $uuid, LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid') ?? $auth->establishment_uuid;

        try {
            return DB::transaction(function () use ($auth, $establishmentUuid, $uuid) {

                $invoice = Invoice::where('uuid', $uuid)
                    ->where('establishment_uuid', $establishmentUuid)
                    ->firstOrFail();

                $invoice->update([
                    'regulated'  => StateFacture::CANCELLED->value,
                    'updated_by' => $auth->id,
                ]);

                $facture = InvoicesFacture::where('invoices_uuid', $invoice->uuid)->first();

                if ($facture) {
                    $facture->update([
                        'state_facture' => StateFacture::CANCELLED->value,
                        'cancelled_at'  => now(),
                        'cancelled_by'  => $auth->id,
                        'updated_by'    => $auth->id,
                    ]);
                }

                $invoice->load(['client', 'items.prestation', 'facture']);

                return response()->json([
                    'success' => true,
                    'message' => 'Facture annulée avec succès.',
                    'data'    => $invoice
                ], 200);
            });

        } catch (\Exception $e) {
            Log::error("Erreur lors de l'annulation de la facture ($uuid) : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'annulation de la facture.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }


    public function initiatePayment(Request $request, $uuid, LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $invoice = Invoice::with('client')->where('uuid', $uuid)->firstOrFail();
        $paymentMethodInput = $request->input('payment_method', PaymentMethodEnum::CASH->value);

        $user = auth()->user();
        $establishmentUuid = $request->header('X-Establishment-Uuid') ?? $user->establishment_uuid;

        $amountToPay = $invoice->total_to_pay ?? $invoice->total_amount;

        if ($paymentMethodInput === PaymentMethodEnum::CASH->value) {
            \DB::transaction(function () use ($invoice, $amountToPay, $establishmentUuid, $user, $paymentMethodInput) {
                \App\Models\Payment::create([
                    'establishment_uuid' => $establishmentUuid,
                    'invoices_uuid'      => $invoice->uuid,
                    'amount'             => $amountToPay,
                    'payment_method'     => $paymentMethodInput,
                    'gateway'            => 'manual',
                    'status'             => 'success',
                    'created_by'         => $user->id,
                ]);

                $invoice->update([
                    'regulated' => StateFacture::PAID->value,
                    'state'     => true,
                ]);

                if ($invoice->facture) {
                    $invoice->facture->update([
                        'state_facture' => StateFacture::PAID->value
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Paiement en espèces validé avec succès.'
            ]);
        }

        $reference = 'INV_' . $invoice->code . '_' . time();
        $publicKey = config('services.notchpay.public_key');

        $response = Http::withHeaders([
            'Authorization' => $publicKey,
            'Accept'        => 'application/json',
        ])
            ->post('https://api.notchpay.co/payments/initialize', [
                'email'        => $invoice->client->email ?? 'client@glowcare.com',
                'name'         => $invoice->client->full_name ?? 'Client Institut',
                'amount'       => (int) $amountToPay,
                'currency'     => 'XAF',
                'reference'    => $reference,
                'description'  => 'Paiement de la facture ' . $invoice->code,
                'callback_url' => 'http://localhost:4300/admin/invoices-index',
            ]);

        $result = $response->json();

        if (!$response->successful()) {
            Log::error("Erreur API NotchPay - Status: " . $response->status(), [
                'response' => $result,
                'payload' => [
                    'amount' => (int) $amountToPay,
                    'reference' => $reference
                ]
            ]);
        }

        if ($response->successful() && isset($result['authorization_url'])) {
            \DB::transaction(function () use ($establishmentUuid, $invoice, $amountToPay, $paymentMethodInput, $reference, $result, $user) {
                \App\Models\Payment::create([
                    'establishment_uuid' => $establishmentUuid,
                    'invoices_uuid'      => $invoice->uuid,
                    'amount'             => $amountToPay,
                    'payment_method'     => $paymentMethodInput,
                    'gateway'            => 'notchpay',
                    'reference'          => $reference,
                    'status'             => 'pending',
                    'gateway_response'   => $result,
                    'created_by'         => $user->id,
                ]);

                $invoice->update([
                    'regulated' => StateFacture::PAID->value,
                    'state'     => true,
                ]);

                if ($invoice->facture) {
                    $invoice->facture->update([
                        'state_facture' => StateFacture::PAID->value
                    ]);
                }
            });

            return response()->json([
                'success'     => true,
                'payment_url' => $result['authorization_url']
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => "Erreur lors de l'initialisation avec Notch Pay",
            'error'   => $result
        ], 400);
    }



}
