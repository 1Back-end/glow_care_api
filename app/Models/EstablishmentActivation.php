<?php

namespace App\Models;

use App\Enums\ActivationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EstablishmentActivation extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'establishment_activations';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'establishment_uuid',
        'license_uuid',
        'starts_at',
        'expires_at',
        'is_active',
        'created_by',
        'updated_by',
        'status',
        'otp',
        'is_used',
        'otp_expired_at'
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'starts_at'  => 'datetime',
        'expires_at' => 'datetime',
        'otp_expired_at' => 'datetime',
        'is_used'    => 'boolean',
        'status'     => ActivationStatus::class,
    ];

    protected $hidden = [
        'otp'
    ];
    protected $appends = [
        'subscription_message',
    ];
    protected function subscriptionMessage(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->expires_at) {
                    return "Expiration : Non définie";
                }

                if ($this->expires_at->isPast()) {
                    return "Expiration : Expiré";
                }
                $relativeTime = $this->expires_at->diffForHumans(now(), [
                    'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
                    'parts'  => 1,
                ]);

                return "Expiration : " . ucfirst($relativeTime);
            }
        );
    }


    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid', 'uuid');
    }

    public function license()
    {
        return $this->belongsTo(License::class, 'license_uuid', 'uuid');
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
