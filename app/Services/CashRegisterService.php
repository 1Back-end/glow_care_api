<?php

namespace App\Services;

use App\Enums\CashRegisterStatus;
use App\Models\CashRegister;
use App\Models\CashRegisterSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CashRegisterService
{
    /**
     * Résout et normalise le statut de la caisse vers CashRegisterStatus.
     */
    public function resolveStatus(CashRegister $cashRegister): ?CashRegisterStatus
    {
        if ($cashRegister->status instanceof CashRegisterStatus) {
            return $cashRegister->status;
        }

        return CashRegisterStatus::tryFrom(strtolower(trim((string) $cashRegister->status)));
    }

    /**
     * Vérifie le code secret hashé de la caisse.
     */
    public function isValidSecretKey(CashRegister $cashRegister, string $secretKey): bool
    {
        $hashedKey = (string) $cashRegister->makeVisible('secret_key')->secret_key;

        if ($hashedKey === '') {
            return false;
        }

        return Hash::check($secretKey, $hashedKey);
    }

    /**
     * Vérifie si la caisse est active et ouverte pour facturer / encaisser.
     * Lève une ValidationException si ce n'est pas le cas.
     *
     * @throws ValidationException
     */
    public function ensureCanInvoice(?CashRegister $cashRegister): CashRegister
    {
        if (!$cashRegister || !$cashRegister->is_active) {
            throw ValidationException::withMessages([
                'cash_register' => ['Aucune caisse active n\'est associée pour effectuer une facturation.'],
            ]);
        }

        $status = $this->resolveStatus($cashRegister);

        if ($status === CashRegisterStatus::CLOSED) {
            throw ValidationException::withMessages([
                'cash_register' => ['La caisse est fermée. Veuillez l\'ouvrir avant de facturer.'],
            ]);
        }

        if ($status === CashRegisterStatus::PAUSED) {
            throw ValidationException::withMessages([
                'cash_register' => ['La caisse est actuellement en pause. Veuillez la reprendre avant de facturer.'],
            ]);
        }

        if ($status !== CashRegisterStatus::OPEN) {
            throw ValidationException::withMessages([
                'cash_register' => ['L\'état de la caisse ne permet pas d\'effectuer une facturation.'],
            ]);
        }

        return $cashRegister;
    }

    /**
     * Variante booléenne pratique pour les contrôles rapides (ex. UI / conditions).
     */
    public function canInvoice(?CashRegister $cashRegister): bool
    {
        if (!$cashRegister || !$cashRegister->is_active) {
            return false;
        }

        return $this->resolveStatus($cashRegister) === CashRegisterStatus::OPEN;
    }

    /**
     * Ouvre une caisse et démarre une nouvelle session.
     *
     * @throws ValidationException
     */
    public function open(CashRegister $cashRegister, string $secretKey, ?int $userId): array
    {
        if (!$cashRegister->is_active) {
            throw ValidationException::withMessages([
                'cash_register' => ['Cette caisse est inactive.'],
            ]);
        }

        if (!$this->isValidSecretKey($cashRegister, $secretKey)) {
            throw ValidationException::withMessages([
                'secret_key' => ['Code secret incorrect.'],
            ]);
        }

        $status = $this->resolveStatus($cashRegister);

        if ($status === CashRegisterStatus::OPEN) {
            throw ValidationException::withMessages([
                'status' => ['Cette caisse est déjà ouverte.'],
            ]);
        }

        $now = now();
        $openingBalance = (float) ($cashRegister->current_balance ?? $cashRegister->initial_balance ?? 0);

        $session = DB::transaction(function () use ($cashRegister, $openingBalance, $userId, $now) {
            CashRegisterSession::where('cash_register_uuid', $cashRegister->uuid)
                ->whereIn('status', [
                    CashRegisterStatus::OPEN->value,
                    CashRegisterStatus::PAUSED->value,
                ])
                ->update([
                    'status'     => CashRegisterStatus::CLOSED->value,
                    'closed_at'  => $now,
                    'closed_by'  => $userId,
                    'updated_by' => $userId,
                ]);

            $createdSession = CashRegisterSession::create([
                'cash_register_uuid' => $cashRegister->uuid,
                'establishment_uuid' => $cashRegister->establishment_uuid,
                'user_id'            => $userId,
                'status'             => CashRegisterStatus::OPEN->value,
                'opening_balance'    => $openingBalance,
                'opened_at'          => $now,
                'opened_by'          => $userId,
                'created_by'         => $userId,
                'updated_by'         => $userId,
            ]);

            $cashRegister->update([
                'status'          => CashRegisterStatus::OPEN,
                'current_balance' => $openingBalance,
                'updated_by'      => $userId,
            ]);

            return $createdSession;
        });

        return [
            'cash_register' => $cashRegister->fresh(['establishment', 'user']),
            'session'       => $session,
        ];
    }

    /**
     * Met la caisse en pause (uniquement si elle est OPEN).
     *
     * @throws ValidationException
     */
    public function pause(CashRegister $cashRegister, ?int $userId): array
    {
        if (!$cashRegister->is_active) {
            throw ValidationException::withMessages([
                'cash_register' => ['Cette caisse est inactive.'],
            ]);
        }

        $status = $this->resolveStatus($cashRegister);

        if ($status !== CashRegisterStatus::OPEN) {
            throw ValidationException::withMessages([
                'status' => ['Seule une caisse ouverte peut être mise en pause.'],
            ]);
        }

        $now = now();

        DB::transaction(function () use ($cashRegister, $userId, $now) {
            CashRegisterSession::where('cash_register_uuid', $cashRegister->uuid)
                ->where('status', CashRegisterStatus::OPEN->value)
                ->latest('opened_at')
                ->limit(1)
                ->update([
                    'status'     => CashRegisterStatus::PAUSED->value,
                    'paused_at'  => $now,
                    'updated_by' => $userId,
                ]);

            $cashRegister->update([
                'status'     => CashRegisterStatus::PAUSED,
                'updated_by' => $userId,
            ]);
        });

        $activeSession = CashRegisterSession::where('cash_register_uuid', $cashRegister->uuid)
            ->latest('opened_at')
            ->first();

        return [
            'cash_register' => $cashRegister->fresh(['establishment', 'user']),
            'session'       => $activeSession,
        ];
    }

    /**
     * Reprend la caisse (uniquement si elle est PAUSED).
     *
     * @throws ValidationException
     */
    public function resume(CashRegister $cashRegister, string $secretKey, ?int $userId): array
    {
        if (!$cashRegister->is_active) {
            throw ValidationException::withMessages([
                'cash_register' => ['Cette caisse est inactive.'],
            ]);
        }

        if (!$this->isValidSecretKey($cashRegister, $secretKey)) {
            throw ValidationException::withMessages([
                'secret_key' => ['Code secret incorrect.'],
            ]);
        }

        $status = $this->resolveStatus($cashRegister);

        if ($status !== CashRegisterStatus::PAUSED) {
            throw ValidationException::withMessages([
                'status' => ['Seule une caisse en pause peut être reprise.'],
            ]);
        }

        $now = now();

        DB::transaction(function () use ($cashRegister, $userId, $now) {
            CashRegisterSession::where('cash_register_uuid', $cashRegister->uuid)
                ->where('status', CashRegisterStatus::PAUSED->value)
                ->latest('opened_at')
                ->limit(1)
                ->update([
                    'status'     => CashRegisterStatus::OPEN->value,
                    'resumed_at' => $now,
                    'updated_by' => $userId,
                ]);

            $cashRegister->update([
                'status'     => CashRegisterStatus::OPEN,
                'updated_by' => $userId,
            ]);
        });

        $activeSession = CashRegisterSession::where('cash_register_uuid', $cashRegister->uuid)
            ->latest('opened_at')
            ->first();

        return [
            'cash_register' => $cashRegister->fresh(['establishment', 'user']),
            'session'       => $activeSession,
        ];
    }

    /**
     * Clôture la caisse et la session active (uniquement si elle n'est pas déjà CLOSED).
     *
     * @throws ValidationException
     */
    public function close(CashRegister $cashRegister, string $secretKey, ?int $userId, ?string $closingNote = null): array
    {
        if (!$cashRegister->is_active) {
            throw ValidationException::withMessages([
                'cash_register' => ['Cette caisse est inactive.'],
            ]);
        }

        if (!$this->isValidSecretKey($cashRegister, $secretKey)) {
            throw ValidationException::withMessages([
                'secret_key' => ['Code secret incorrect.'],
            ]);
        }

        $status = $this->resolveStatus($cashRegister);

        if ($status === CashRegisterStatus::CLOSED) {
            throw ValidationException::withMessages([
                'status' => ['Cette caisse est déjà fermée.'],
            ]);
        }

        $now = now();

        $session = DB::transaction(function () use ($cashRegister, $userId, $now, $closingNote) {
            $activeSession = CashRegisterSession::where('cash_register_uuid', $cashRegister->uuid)
                ->whereIn('status', [
                    CashRegisterStatus::OPEN->value,
                    CashRegisterStatus::PAUSED->value,
                ])
                ->latest('opened_at')
                ->first();

            $balance = (float) ($cashRegister->current_balance ?? 0);

            if ($activeSession) {
                $activeSession->update([
                    'status'                 => CashRegisterStatus::CLOSED->value,
                    'expected_closing_balance' => $balance,
                    'closing_balance'        => $balance,
                    'difference'             => 0,
                    'closed_at'              => $now,
                    'closing_note'           => $closingNote,
                    'closed_by'              => $userId,
                    'updated_by'             => $userId,
                ]);
            }

            $cashRegister->update([
                'status'     => CashRegisterStatus::CLOSED,
                'updated_by' => $userId,
            ]);

            return $activeSession?->fresh();
        });

        return [
            'cash_register' => $cashRegister->fresh(['establishment', 'user']),
            'session'       => $session,
        ];
    }
}
