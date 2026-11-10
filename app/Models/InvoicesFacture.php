<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoicesFacture extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'invoices_factures';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'sequence_number',
        'invoices_uuid',
        'establishment_uuid',
        'type',
        'state_facture',
        'amount',
        'date_fac',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date_fac' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->code)) {
                DB::transaction(function () use ($model) {
                    $prefix = 'FAC';
                    $yearMonth = now()->format('Ym');

                    $latestFacture = static::where('establishment_uuid', $model->establishment_uuid)
                        ->where('code', 'like', $prefix . $yearMonth . '%')
                        ->orderBy('code', 'desc')
                        ->lockForUpdate()
                        ->first();

                    if ($latestFacture) {
                        $lastSequence = intval(substr($latestFacture->code, -4));
                        $nextSequence = $lastSequence + 1;
                    } else {
                        $nextSequence = 1;
                    }

                    $formattedSequence = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);

                    $model->code = $prefix . $yearMonth . $formattedSequence;

                    if (empty($model->sequence_number)) {
                        $model->sequence_number = $formattedSequence;
                    }
                });
            }
        });
    }

    // Relations
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoices_uuid', 'uuid');
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
