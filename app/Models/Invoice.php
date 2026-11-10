<?php

namespace App\Models;

use App\Enums\PrestationCategory;
use App\Enums\StateFacture;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'invoices';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'code',
        'establishment_uuid',
        'client_uuid',
        'regulated',
        'created_by',
        'type',
        'programmation_date',
        'state',
        'updated_by',
        'is_have_facture',
        'cancelled_at',
        'cancelled_by',
        'qr_code'
    ];

    protected $casts = [
        'state' => 'boolean',
        'programmation_date' => 'datetime',
        'cancelled_at' => 'datetime',
        'type' => PrestationCategory::class,
        'regulated' => StateFacture::class,
    ];
    protected $appends = [
        'type_label',
        'regulated_label',
        'total_amount',
        'total_remise',
        'qr_code_file',
    ];
    public function getQrCodeFileAttribute()
    {
        if ($this->qr_code) {
            return Storage::disk('public')->url($this->qr_code);
        }
        return null;
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->code)) {
                DB::transaction(function () use ($model) {
                    $prefix = 'INV';
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

    public function getTypeLabelAttribute(): string
    {
        if ($this->type instanceof PrestationCategory) {
            return method_exists($this->type, 'label') ? $this->type->label() : $this->type->value;
        }

        return PrestationCategory::safeLabel($this->type);
    }

    public function getRegulatedLabelAttribute(): string
    {
        return StateFacture::safeLabel($this->regulated);
    }
    public function getTotalAmountAttribute(): float
    {
        return (float) $this->items->sum(function ($item) {
            $subtotal = ($item->quantity ?? 0) * ($item->price ?? 0);
            $remise = $item->remise ?? 0;
            return max(0, $subtotal - $remise);
        });
    }

    public function getTotalRemiseAttribute(): float
    {
        return (float) $this->items->sum(function ($item) {
            return (float) ($item->remise ?? 0);
        });
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid', 'uuid');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function facture()
    {
        return $this->hasOne(InvoicesFacture::class, 'invoices_uuid', 'uuid');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoices_uuid', 'uuid');
    }
    public function cancellor()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
