<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'transactions';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'reference',
        'type',
        'label',
        'regulation_methods_uuid',
        'establishment_uuid',
        'user_id',
        'created_by',
        'updated_by',
        'amount',
        'transaction_date',
        'status',
        'transactionable_type',
        'transactionable_id',
    ];

    protected $casts = [
        'type' => TransactionType::class,
        'status' => TransactionStatus::class,
        'amount' => 'decimal:2',
        'transaction_date' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            if (empty($transaction->reference)) {
                $last = static::withTrashed()
                    ->orderByDesc('created_at')
                    ->first();

                $nextNumber = 1;
                if ($last && preg_match('/(\d+)$/', $last->reference, $matches)) {
                    $nextNumber = ((int) $matches[1]) + 1;
                }

                $transaction->reference = 'TRX-' . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
            }

            if (empty($transaction->transaction_date)) {
                $transaction->transaction_date = now();
            }

            if (auth()->check()) {
                if (empty($transaction->created_by)) {
                    $transaction->created_by = auth()->id();
                }
                if (empty($transaction->user_id)) {
                    $transaction->user_id = auth()->id();
                }
            }
        });

        static::updating(function (Transaction $transaction) {
            if (auth()->check()) {
                $transaction->updated_by = auth()->id();
            }
        });
    }

    /**
     * Nécessaire avec HasUuids lorsque la clé primaire s'appelle 'uuid' plutôt que 'id'.
     */
    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }

    public function regulationMethod(): BelongsTo
    {
        return $this->belongsTo(RegulationMethod::class, 'regulation_methods_uuid', 'uuid');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid', 'uuid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }

    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }
}
