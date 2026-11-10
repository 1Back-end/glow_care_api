<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionRequest extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'subscription_requests';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'is_active',
        'created_by',
        'validated_by',
        'rejected_by',
        'reason_for_rejected',
        'cancelled_by',
        'reason_for_cancelled',
        'updated_by',
        'status',
        'establishment_uuid',
        'license_uuid',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'status' => SubscriptionStatus::class,
    ];
    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return SubscriptionStatus::safeLabel($this->status);
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

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
