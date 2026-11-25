<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppointmentOriginEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StateFacture;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicesFacture;
use App\Models\Payment;
use App\Models\RegulationMethod;
use App\Models\Transaction;
use App\Models\UserEstablishment;
use App\Services\CashRegisterService;
use App\Services\LicenseService;
use App\Services\UserAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * @permission_category Facturations des prestations
 */

class InvoiceController extends Controller
{
    protected LicenseService $licenseService;
    protected UserAuthorizationService $authService;
    protected  CashRegisterService $cashRegisterService;

    public function __construct(
        LicenseService $licenseService,
        UserAuthorizationService $authService,
        CashRegisterService $cashRegisterService

    ) {
        $this->licenseService = $licenseService;
        $this->authService = $authService;
        $this->cashRegisterService = $cashRegisterService;
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

        $query = Invoice::with(['client:uuid,code,full_name', 'creator:id,nom_utilisateur', 'updater:id,nom_utilisateur', 'items.prestation','facture',
            'payments' => function ($query) {
                $query->where('status', '!=', 'cancelled');
            },
            'payments.regulation_methods:uuid,name'
            ])
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
    public function store(Request $request, LicenseService $licenseService, CashRegisterService $cashRegisterService)
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

        $cashRegister = CashRegister::query()
            ->where('user_id', $user->id)
            ->when($establishmentUuid, fn ($q) => $q->where('establishment_uuid', $establishmentUuid))
            ->first();

        try {
            $cashRegisterService->ensureCanInvoice($cashRegister);
        } catch (ValidationException $e) {
            $firstMessage = collect($e->errors())->flatten()->first()
                ?? 'La caisse ne permet pas la facturation.';

            return response()->json([
                'success'        => false,
                'message'        => $firstMessage,
                'current_status' => $cashRegister
                    ? $cashRegisterService->resolveStatus($cashRegister)?->value
                    : null,
                'errors'         => $e->errors(),
            ], 422);
        }

        try {
            return DB::transaction(function () use ($validated, $user, $establishmentUuid, $cashRegister) {

                $stateFacture = $validated['regulated'] ?? StateFacture::CREATE->value;

                $invoice = Invoice::create([
                    'establishment_uuid' => $establishmentUuid,
                    'client_uuid'        => $validated['client_uuid'],
                    'cash_register_uuid' => $cashRegister->uuid,
                    'regulated'          => $stateFacture,
                    'programmation_date' => $validated['programmation_date'] ?? now(),
                    'state'              => $validated['state'] ?? false,
                    'appointment_origin' => $validated['appointment_origin'] ?? AppointmentOriginEnum::WALK_IN->value,
                    'created_by'         => $user->id,
                ]);

                $invoice->refresh();

                $transactionAmount = $invoice->total_to_pay ?? $invoice->total_amount;

                $regulationMethodUuid = $validated['regulation_methods_uuid'] ?? null;

                Transaction::create([
                    'type'                   => TransactionType::INCOME->value,
                    'label'                  => 'Facture ' . ($invoice->code ?? $invoice->uuid),
                    'regulation_methods_uuid'=> $regulationMethodUuid,
                    'establishment_uuid'     => $establishmentUuid,
                    'user_id'                => $user->id,
                    'created_by'             => $user->id,
                    'amount'                 => $transactionAmount,
                    'transaction_date'       => now(),
                    'status'                  => TransactionStatus::PENDING->value,
                    'transactionable_type'   => $invoice->getMorphClass(),
                    'transactionable_id'     => $invoice->uuid,
                ]);

                foreach ($validated['items'] as $index => $itemData) {
                    InvoiceItem::create([
                        'invoices_uuid'      => $invoice->uuid,
                        'establishment_uuid' => $establishmentUuid,
                        'cash_register_uuid' => $cashRegister->uuid,
                        'prestation_uuid'    => $itemData['prestation_uuid'],
                        'quantity'           => $itemData['quantity'],
                        'price'              => $itemData['price'],
                        'remise'             => $itemData['remise'] ?? 0,
                        'sequence_number'    => str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                        'created_by'         => $user->id,
                    ]);
                }

                $firstPrestationType = optional($invoice->items()->with('prestation')->first()?->prestation)->type;

                InvoicesFacture::create([
                    'invoices_uuid'      => $invoice->uuid,
                    'establishment_uuid' => $establishmentUuid,
                    'cash_register_uuid' => $cashRegister->uuid,
                    'type'               => $invoice->type ?? $firstPrestationType,
                    'state_facture'      => $stateFacture,
                    'amount'             => $invoice->total_amount,
                    'date_fac'           => now(),
                    'created_by'         => $user->id,
                ]);

                $invoice->load(['client', 'establishment', 'cashRegister', 'items.prestation', 'facture']);

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
                    'data'    => $invoice,
                ], 201);
            });

        } catch (\Exception $e) {
            Log::error("Erreur lors de la création de la facture : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'enregistrement de la facture.",
                'error'   => $e->getMessage(),
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
    public function update(Request $request, $uuid, LicenseService $licenseService, CashRegisterService $cashRegisterService)
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

        $cashRegister = CashRegister::query()
            ->where('user_id', $user->id)
            ->when($establishmentUuid, fn ($q) => $q->where('establishment_uuid', $establishmentUuid))
            ->first();

        try {
            $cashRegisterService->ensureCanInvoice($cashRegister);
        } catch (ValidationException $e) {
            $firstMessage = collect($e->errors())->flatten()->first()
                ?? 'La caisse ne permet pas la mise à jour de la facturation.';

            return response()->json([
                'success'        => false,
                'message'        => $firstMessage,
                'current_status' => $cashRegister
                    ? $cashRegisterService->resolveStatus($cashRegister)?->value
                    : null,
                'errors'         => $e->errors(),
            ], 422);
        }

        try {
            return DB::transaction(function () use ($validated, $user, $establishmentUuid, $uuid, $cashRegister) {

                $invoice = Invoice::where('uuid', $uuid)
                    ->where('establishment_uuid', $establishmentUuid)
                    ->firstOrFail();

                $invoice->update([
                    'client_uuid'        => $validated['client_uuid'],
                    'cash_register_uuid' => $cashRegister->uuid,
                    'regulated'          => $validated['regulated'] ?? $invoice->regulated,
                    'programmation_date' => $validated['programmation_date'] ?? $invoice->programmation_date,
                    'state'              => $validated['state'] ?? $invoice->state,
                    'appointment_origin' => $validated['appointment_origin'] ?? $invoice->appointment_origin,
                    'updated_by'         => $user->id,
                ]);
                $invoice->refresh();

                $transactionAmount = $invoice->total_to_pay ?? $invoice->total_amount;
                $label = 'Facture ' . ($facture->reference ?? $invoice->code ?? $invoice->uuid);

                $transaction = Transaction::where('transactionable_type', $invoice->getMorphClass())
                    ->where('transactionable_id', $invoice->uuid)
                    ->first();

                if ($transaction) {
                    $transaction->update([
                        'label'      => $label,
                        'amount'     => $transactionAmount,
                        'updated_by' => $user->id,
                    ]);
                } else {
                    Transaction::create([
                        'type'                    => TransactionType::INCOME->value,
                        'label'                   => $label,
                        'regulation_methods_uuid' => null,
                        'establishment_uuid'      => $establishmentUuid,
                        'user_id'                 => $invoice->created_by ?? $user->id,
                        'created_by'              => $invoice->created_by ?? $user->id,
                        'updated_by'              => $user->id,
                        'amount'                  => $transactionAmount,
                        'transaction_date'        => $invoice->created_at ?? now(),
                        'status'                  => TransactionStatus::PENDING->value,
                        'transactionable_type'    => $invoice->getMorphClass(),
                        'transactionable_id'      => $invoice->uuid,
                    ]);
                }

                $invoice->items()->delete();

                foreach ($validated['items'] as $index => $itemData) {
                    InvoiceItem::create([
                        'invoices_uuid'      => $invoice->uuid,
                        'establishment_uuid' => $establishmentUuid,
                        'cash_register_uuid' => $cashRegister->uuid,
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
                        'cash_register_uuid' => $cashRegister->uuid,
                        'type'               => $invoice->type ?? $firstPrestationType,
                        'state_facture'      => $invoice->regulated,
                        'amount'             => $invoice->total_amount,
                        'updated_by'         => $user->id,
                    ]);
                } else {
                    InvoicesFacture::create([
                        'invoices_uuid'      => $invoice->uuid,
                        'establishment_uuid' => $establishmentUuid,
                        'cash_register_uuid' => $cashRegister->uuid,
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

                $invoice->load(['client', 'cashRegister', 'items.prestation', 'facture', 'medias']);

                return response()->json([
                    'success' => true,
                    'message' => 'Facturation mise à jour avec succès.',
                    'data'    => $invoice,
                ], 200);
            });

        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour de la facture ($uuid) : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de la mise à jour de la facture.",
                'error'   => $e->getMessage(),
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
                ->with(['client', 'creator', 'updater', 'items.prestation','establishment','facture','payments.regulation_methods:uuid,name','payments.cancellor:id,nom_utilisateur','payments.creator:id,nom_utilisateur'])
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

                $referenceFacture = $invoice->code ?? $invoice->uuid;

                $transaction = Transaction::where('transactionable_type', $invoice->getMorphClass())
                    ->where('transactionable_id', $invoice->uuid)
                    ->first();

                if ($transaction) {
                    $transaction->update([
                        'label'      => 'Annulation Facture ' . $referenceFacture,
                        'status'     => TransactionStatus::CANCELLED->value,
                        'updated_by' => $auth->id,
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


    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission InvoiceController::initiatePayment
     * @permission_desc Encaisser une facture
     */
    public function initiatePayment(Request $request, $uuid, LicenseService $licenseService, CashRegisterService $cashRegisterService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid') ?? $auth->establishment_uuid;

        $cashRegister = CashRegister::query()
            ->where('user_id', $auth->id)
            ->when($establishmentUuid, fn ($q) => $q->where('establishment_uuid', $establishmentUuid))
            ->first();

        try {
            $cashRegisterService->ensureCanInvoice($cashRegister);
        } catch (ValidationException $e) {
            $firstMessage = collect($e->errors())->flatten()->first()
                ?? 'La caisse ne permet pas d\'effectuer un encaissement.';

            return response()->json([
                'success'        => false,
                'message'        => $firstMessage,
                'current_status' => $cashRegister
                    ? $cashRegisterService->resolveStatus($cashRegister)?->value
                    : null,
                'errors'         => $e->errors(),
            ], 422);
        }

        $invoice = Invoice::where('establishment_uuid', $establishmentUuid)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $validated = $request->validate([
            'regulations'             => 'required|array|min:1',
            'regulations.*.method'    => 'required|exists:regulation_methods,uuid',
            'regulations.*.amount'    => 'required|numeric|gt:0',
            'regulations.*.phone'     => 'nullable|string',
            'regulations.*.reference' => 'nullable|string',
            'regulations.*.comment'   => 'nullable|string',
        ], [
            'regulations.*.amount.gt' => 'Le montant du règlement doit être supérieur à 0.',
        ]);

        $totalInvoiceAmount = (float) ($invoice->total_amount ?? $invoice->amount ?? 0);

        $existingPaymentsSum = (float) Payment::where('invoices_uuid', $invoice->uuid)
            ->where('status', '!=', 'cancelled')
            ->sum('amount');

        $newPaymentsSum = (float) collect($validated['regulations'])->sum('amount');

        if (($existingPaymentsSum + $newPaymentsSum) > $totalInvoiceAmount) {
            $remainingDue = max(0, $totalInvoiceAmount - $existingPaymentsSum);
            throw ValidationException::withMessages([
                'regulations' => ["Le montant total des paiements dépasse le montant dû de la facture. Reste à payer : {$remainingDue} FCFA."],
            ]);
        }

        $methodUuids = collect($validated['regulations'])->pluck('method')->unique()->values();
        $regulationMethods = RegulationMethod::whereIn('uuid', $methodUuids)->get()->keyBy('uuid');

        foreach ($validated['regulations'] as $index => $regData) {
            $regulationMethod = $regulationMethods->get($regData['method']);

            if ($regulationMethod) {
                if (!empty($regulationMethod->comment_required) && empty($regData['comment'])) {
                    throw ValidationException::withMessages([
                        "regulations.{$index}.comment" => ["Le commentaire est obligatoire pour le mode de règlement : {$regulationMethod->name}"],
                    ]);
                }

                if (!empty($regulationMethod->reference_required) && empty($regData['reference'])) {
                    throw ValidationException::withMessages([
                        "regulations.{$index}.reference" => ["La référence est obligatoire pour le mode de règlement : {$regulationMethod->name}"],
                    ]);
                }

                if (!empty($regulationMethod->phone_required) && empty($regData['phone'])) {
                    throw ValidationException::withMessages([
                        "regulations.{$index}.phone" => ["Le numéro de téléphone est obligatoire pour le mode de règlement : {$regulationMethod->name}"],
                    ]);
                }
            }
        }

        return DB::transaction(function () use (
            $validated,
            $invoice,
            $establishmentUuid,
            $cashRegister,
            $auth,
            $totalInvoiceAmount,
            $existingPaymentsSum,
            $newPaymentsSum,
            $regulationMethods
        ) {
            $invoiceRef = $invoice->facture?->reference ?? $invoice->code ?? $invoice->uuid;

            foreach ($validated['regulations'] as $regData) {
                $payment = Payment::create([
                    'establishment_uuid'      => $establishmentUuid,
                    'cash_register_uuid'      => $cashRegister->uuid,
                    'invoices_uuid'           => $invoice->uuid,
                    'regulation_methods_uuid' => $regData['method'],
                    'amount'                  => $regData['amount'],
                    'phone'                   => $regData['phone'] ?? null,
                    'reference'               => $regData['reference'] ?? null,
                    'comment'                 => $regData['comment'] ?? null,
                    'status'                  => 'completed',
                    'created_by'              => $auth->id ?? null,
                ]);

                $methodName = $regulationMethods->get($regData['method'])?->name;

                Transaction::create([
                    'type'                    => TransactionType::INCOME->value,
                    'label'                   => 'Encaissement Facture ' . $invoiceRef . ($methodName ? " ({$methodName})" : ''),
                    'regulation_methods_uuid' => $regData['method'],
                    'establishment_uuid'      => $establishmentUuid,
                    'user_id'                 => $auth->id ?? null,
                    'created_by'              => $auth->id ?? null,
                    'amount'                  => $regData['amount'],
                    'transaction_date'        => now(),
                    'status'                  => TransactionStatus::VALIDATED->value,
                    'transactionable_type'    => $payment->getMorphClass(),
                    'transactionable_id'      => $payment->uuid,
                ]);
            }

            $totalPaidNow = $existingPaymentsSum + $newPaymentsSum;
            $remainingAmount = max(0, $totalInvoiceAmount - $totalPaidNow);

            if ($totalPaidNow >= $totalInvoiceAmount && $totalInvoiceAmount > 0) {
                $newStatus = StateFacture::PAID->value;
            } elseif ($totalPaidNow > 0) {
                $newStatus = StateFacture::IN_PROGRESS->value;
            } else {
                $newStatus = StateFacture::CREATE->value;
            }

            $invoice->update([
                'regulated'  => $newStatus,
                'updated_by' => $auth->id ?? null,
            ]);

            $pendingInvoiceTx = Transaction::where('transactionable_type', $invoice->getMorphClass())
                ->where('transactionable_id', $invoice->uuid)
                ->where('status', TransactionStatus::PENDING->value)
                ->first();

            if ($pendingInvoiceTx) {
                if ($newStatus === StateFacture::PAID->value) {
                    $pendingInvoiceTx->update([
                        'label'      => 'Facture ' . $invoiceRef . ' (Soldée)',
                        'status'     => TransactionStatus::CANCELLED->value,
                        'updated_by' => $auth->id ?? null,
                    ]);
                } elseif ($remainingAmount > 0) {
                    $pendingInvoiceTx->update([
                        'label'      => 'Facture ' . $invoiceRef . ' (Reste à payer)',
                        'amount'     => $remainingAmount,
                        'updated_by' => $auth->id ?? null,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Encaissements enregistrés avec succès.',
                'data'    => $invoice->fresh()->load(['cashRegister', 'payments.regulation_methods', 'payments.cancellor']),
            ], 200);
        });
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission InvoiceController::cancelPayment
     * @permission_desc Annuler un encaissement
     */
    public function cancelPayment(Request $request, $uuid, LicenseService $licenseService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid') ?? $auth->establishment_uuid;

        $validated = $request->validate([
            'cancellation_reason' => 'required|string|min:3',
        ], [
            'cancellation_reason.required' => 'La raison de l\'annulation est obligatoire.',
            'cancellation_reason.min'      => 'La raison de l\'annulation doit contenir au moins 3 caractères.',
        ]);

        $payment = Payment::where('establishment_uuid', $establishmentUuid)
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($payment->status === 'cancelled') {
            throw ValidationException::withMessages([
                'payment' => ['Ce paiement a déjà été annulé.']
            ]);
        }

        $invoice = Invoice::where('establishment_uuid', $establishmentUuid)
            ->where('uuid', $payment->invoices_uuid)
            ->firstOrFail();

        return DB::transaction(function () use ($payment, $invoice, $validated, $auth) {
            $payment->update([
                'status'              => 'cancelled',
                'cancellation_reason' => $validated['cancellation_reason'],
                'cancelled_at'        => now(),
                'cancelled_by'        => $auth->id ?? null,
                'updated_by'          => $auth->id ?? null,
            ]);

            $invoiceRef = $invoice->facture?->reference ?? $invoice->code ?? $invoice->uuid;

            // 1. Annuler la transaction validée rattachée à ce paiement
            $paymentTransaction = Transaction::where('transactionable_type', $payment->getMorphClass())
                ->where('transactionable_id', $payment->uuid)
                ->first();

            if ($paymentTransaction) {
                $paymentTransaction->update([
                    'label'      => 'Annulation Paiement Facture ' . $invoiceRef,
                    'status'     => TransactionStatus::CANCELLED->value,
                    'updated_by' => $auth->id ?? null,
                ]);
            }

            $totalInvoiceAmount = (float) ($invoice->total_amount ?? $invoice->amount ?? 0);

            $totalPaidNow = (float) Payment::where('invoices_uuid', $invoice->uuid)
                ->where('status', '!=', 'cancelled')
                ->sum('amount');

            $remainingAmount = max(0, $totalInvoiceAmount - $totalPaidNow);

            if ($totalPaidNow >= $totalInvoiceAmount && $totalInvoiceAmount > 0) {
                $newStatus = StateFacture::PAID->value;
            } elseif ($totalPaidNow > 0) {
                $newStatus = StateFacture::IN_PROGRESS->value;
            } else {
                $newStatus = StateFacture::CREATE->value;
            }

            $invoice->update([
                'regulated'  => $newStatus,
                'updated_by' => $auth->id ?? null,
            ]);

            $pendingInvoiceTx = Transaction::where('transactionable_type', $invoice->getMorphClass())
                ->where('transactionable_id', $invoice->uuid)
                ->where('status', TransactionStatus::PENDING->value)
                ->first();

            if ($pendingInvoiceTx && $remainingAmount > 0) {
                $pendingInvoiceTx->update([
                    'label'      => 'Facture ' . $invoiceRef . ($totalPaidNow > 0 ? ' (Reste à payer)' : ''),
                    'amount'     => $remainingAmount,
                    'updated_by' => $auth->id ?? null,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Paiement annulé avec succès.',
                'data'    => $invoice->fresh()->load(['payments.regulation_methods', 'payments.cancellor']),
            ], 200);
        });
    }

    /**
     * @param Request $request
     * @return JsonResponse
     *
     * @permission InvoiceController::updatePayment
     * @permission_desc Modifier un encaissement
     */
    public function updatePayment(Request $request, $uuid, LicenseService $licenseService, CashRegisterService $cashRegisterService)
    {
        if ($errorResponse = $licenseService->validateActiveLicense($request)) {
            return $errorResponse;
        }

        $auth = auth()->user();
        $this->authService->denyIfSuperAdmin($auth);

        $establishmentUuid = $request->header('X-Establishment-Uuid') ?? $auth->establishment_uuid;

        $cashRegister = CashRegister::query()
            ->where('user_id', $auth->id)
            ->when($establishmentUuid, fn ($q) => $q->where('establishment_uuid', $establishmentUuid))
            ->first();

        try {
            $cashRegisterService->ensureCanInvoice($cashRegister);
        } catch (ValidationException $e) {
            $firstMessage = collect($e->errors())->flatten()->first()
                ?? 'La caisse ne permet pas de modifier un paiement.';

            return response()->json([
                'success'        => false,
                'message'        => $firstMessage,
                'current_status' => $cashRegister
                    ? $cashRegisterService->resolveStatus($cashRegister)?->value
                    : null,
                'errors'         => $e->errors(),
            ], 422);
        }

        $payment = Payment::where('uuid', $uuid)
            ->firstOrFail();

        if ($payment->status === 'cancelled') {
            throw ValidationException::withMessages([
                'payment' => ['Impossible de modifier un paiement annulé.'],
            ]);
        }

        $validated = $request->validate([
            'method'    => 'required|exists:regulation_methods,uuid',
            'amount'    => 'required|numeric|gt:0',
            'phone'     => 'nullable|string',
            'reference' => 'nullable|string',
            'comment'   => 'nullable|string',
        ], [
            'amount.gt' => 'Le montant du règlement doit être supérieur à 0.',
        ]);

        $invoice = Invoice::where('uuid', $payment->invoices_uuid)
            ->firstOrFail();

        $totalInvoiceAmount = (float) ($invoice->total_amount ?? $invoice->amount ?? 0);

        $otherPaymentsSum = (float) Payment::where('invoices_uuid', $invoice->uuid)
            ->where('uuid', '!=', $payment->uuid)
            ->where('status', '!=', 'cancelled')
            ->sum('amount');

        if (($otherPaymentsSum + (float) $validated['amount']) > $totalInvoiceAmount) {
            $remainingDue = max(0, $totalInvoiceAmount - $otherPaymentsSum);
            throw ValidationException::withMessages([
                'amount' => ["Le montant total dépasse le montant dû de la facture. Maximum autorisé pour ce paiement : {$remainingDue} FCFA."],
            ]);
        }

        $regulationMethod = RegulationMethod::where('uuid', $validated['method'])->first();
        if ($regulationMethod) {
            if (!empty($regulationMethod->comment_required) && empty($validated['comment'])) {
                throw ValidationException::withMessages([
                    'comment' => ["Le commentaire est obligatoire pour le mode de règlement : {$regulationMethod->name}"],
                ]);
            }
            if (!empty($regulationMethod->reference_required) && empty($validated['reference'])) {
                throw ValidationException::withMessages([
                    'reference' => ["La référence est obligatoire pour le mode de règlement : {$regulationMethod->name}"],
                ]);
            }
            if (!empty($regulationMethod->phone_required) && empty($validated['phone'])) {
                throw ValidationException::withMessages([
                    'phone' => ["Le numéro de téléphone est obligatoire pour le mode de règlement : {$regulationMethod->name}"],
                ]);
            }
        }

        return DB::transaction(function () use (
            $payment,
            $invoice,
            $cashRegister,
            $validated,
            $auth,
            $totalInvoiceAmount,
            $otherPaymentsSum,
            $regulationMethod,
            $establishmentUuid
        ) {
            $payment->update([
                'cash_register_uuid'      => $cashRegister->uuid,
                'regulation_methods_uuid' => $validated['method'],
                'amount'                  => $validated['amount'],
                'phone'                   => $validated['phone'] ?? null,
                'reference'               => $validated['reference'] ?? null,
                'comment'                 => $validated['comment'] ?? null,
                'updated_by'              => $auth->id ?? null,
            ]);

            $invoiceRef = $invoice->facture?->reference ?? $invoice->code ?? $invoice->uuid;
            $methodName = $regulationMethod?->name;
            $txLabel = 'Encaissement Facture ' . $invoiceRef . ($methodName ? " ({$methodName})" : '');

            $paymentTransaction = Transaction::where('transactionable_type', $payment->getMorphClass())
                ->where('transactionable_id', $payment->uuid)
                ->first();

            if ($paymentTransaction) {
                $paymentTransaction->update([
                    'label'                   => $txLabel,
                    'regulation_methods_uuid' => $validated['method'],
                    'amount'                  => $validated['amount'],
                    'status'                  => TransactionStatus::VALIDATED->value,
                    'updated_by'              => $auth->id ?? null,
                ]);
            } else {
                Transaction::create([
                    'type'                    => TransactionType::INCOME->value,
                    'label'                   => $txLabel,
                    'regulation_methods_uuid' => $validated['method'],
                    'establishment_uuid'      => $payment->establishment_uuid ?? $establishmentUuid,
                    'user_id'                 => $auth->id ?? null,
                    'created_by'              => $auth->id ?? null,
                    'amount'                  => $validated['amount'],
                    'transaction_date'        => now(),
                    'status'                  => TransactionStatus::VALIDATED->value,
                    'transactionable_type'    => $payment->getMorphClass(),
                    'transactionable_id'      => $payment->uuid,
                ]);
            }

            // 2. Recalculer l'état de la facture et le reste à payer
            $totalPaidNow = $otherPaymentsSum + (float) $validated['amount'];
            $remainingAmount = max(0, $totalInvoiceAmount - $totalPaidNow);

            if ($totalPaidNow >= $totalInvoiceAmount && $totalInvoiceAmount > 0) {
                $newStatus = StateFacture::PAID->value;
            } elseif ($totalPaidNow > 0) {
                $newStatus = StateFacture::IN_PROGRESS->value;
            } else {
                $newStatus = StateFacture::CREATE->value;
            }

            $invoice->update([
                'regulated'  => $newStatus,
                'updated_by' => $auth->id ?? null,
            ]);

            // 3. Synchroniser l'éventuelle transaction "pending" liée à la facture
            $pendingInvoiceTx = Transaction::where('transactionable_type', $invoice->getMorphClass())
                ->where('transactionable_id', $invoice->uuid)
                ->where('status', TransactionStatus::PENDING->value)
                ->first();

            if ($pendingInvoiceTx) {
                if ($newStatus === StateFacture::PAID->value) {
                    $pendingInvoiceTx->update([
                        'label'      => 'Facture ' . $invoiceRef . ' (Soldée)',
                        'status'     => TransactionStatus::CANCELLED->value,
                        'updated_by' => $auth->id ?? null,
                    ]);
                } elseif ($remainingAmount > 0) {
                    $pendingInvoiceTx->update([
                        'label'      => 'Facture ' . $invoiceRef . ' (Reste à payer)',
                        'amount'     => $remainingAmount,
                        'updated_by' => $auth->id ?? null,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Paiement modifié avec succès.',
                'data'    => $invoice->fresh()->load(['cashRegister', 'payments.regulation_methods', 'payments.cancellor']),
            ], 200);
        });
    }



}
