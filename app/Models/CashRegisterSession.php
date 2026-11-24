<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class CashRegisterSession extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'cash_registers_sessions';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'cash_register_uuid',
        'establishment_uuid',
        'user_id',
        'status',
        'opening_balance',
        'expected_closing_balance',
        'closing_balance',
        'difference',
        'opened_at',
        'paused_at',
        'resumed_at',
        'closed_at',
        'opening_note',
        'closing_note',
        'opened_by',
        'closed_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'expected_closing_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'opened_at' => 'datetime',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->code)) {
                DB::transaction(function () use ($model) {
                    $prefix = 'CS';
                    $yearMonth = now()->format('Ym');

                    $latest = static::where('establishment_uuid', $model->establishment_uuid)
                        ->where('code', 'like', $prefix . $yearMonth . '%')
                        ->orderBy('code', 'desc')
                        ->lockForUpdate()
                        ->first();

                    $nextSequence = $latest
                        ? intval(substr($latest->code, -4)) + 1
                        : 1;

                    $model->code = $prefix . $yearMonth . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
                });
            }
        });
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_uuid', 'uuid');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid', 'uuid');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
