<?php

namespace App\Models;

use App\Enums\CashRegisterStatus;
use App\Enums\CashRegisterType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class CashRegister extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'cash_registers';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'establishment_uuid',
        'created_by',
        'updated_by',
        'name',
        'initial_balance',
        'current_balance',
        'is_active',
        'type',
        'status',
        'user_id',
        'secret_key',
    ];
    protected $hidden = [
        'secret_key',
    ];

    protected function casts(): array
    {
        return [
            'type' => CashRegisterType::class,
            'status' => CashRegisterStatus::class,
            'initial_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            if (empty($model->code)) {
                DB::transaction(function () use ($model) {
                    $prefix = 'CR';
                    $yearMonth = now()->format('Ym');

                    $latestInvoice = static::where('establishment_uuid', $model->establishment_uuid)
                        ->where('code', 'like', $prefix . $yearMonth . '%')
                        ->orderBy('code', 'desc')
                        ->lockForUpdate()
                        ->first();

                    if ($latestInvoice) {
                        $lastSequence = intval(substr($latestInvoice->code, -4));
                        $nextSequence = $lastSequence + 1;
                    } else {
                        $nextSequence = 1;
                    }

                    $model->code = $prefix . $yearMonth . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
                });
            }
        });
    }


    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
