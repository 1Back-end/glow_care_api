<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'invoices_items';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'sequence_number',
        'invoices_uuid',
        'establishment_uuid',
        'prestation_uuid',
        'quantity',
        'price',
        'remise',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'remise' => 'decimal:2',
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
                    $prefix = 'ITM';
                    $yearMonth = now()->format('Ym');

                    $latestItem = static::where('establishment_uuid', $model->establishment_uuid)
                        ->where('code', 'like', $prefix . $yearMonth . '%')
                        ->orderBy('code', 'desc')
                        ->lockForUpdate()
                        ->first();

                    if ($latestItem) {
                        $lastSequence = intval(substr($latestItem->code, -4));
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

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoices_uuid', 'uuid');
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid', 'uuid');
    }

    public function prestation()
    {
        return $this->belongsTo(Prestation::class, 'prestation_uuid', 'uuid');
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
