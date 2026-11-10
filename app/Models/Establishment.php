<?php

namespace App\Models;

use App\Enums\ActivationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Establishment extends Model
{
    use HasFactory, SoftDeletes,HasUuids;

    protected $table = 'establishments';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'phone',
        'second_phone',
        'email',
        'address',
        'city',
        'activity_sector',
        'logo',
        'cover_image',
        'is_active',
        'created_by',
        'updated_by',
        'reference',
        'owner_uuid',
        'license_uuid',
        'is_used_licence'
    ];

    protected $appends = ['public_logo', 'public_cover_image'];

    public function getPublicLogoAttribute()
    {
        if ($this->logo) {
            return Storage::disk('public')->url($this->logo);
        }
        return null;
    }

    public function getPublicCoverImageAttribute()
    {
        if ($this->cover_image) {
            return Storage::disk('public')->url($this->cover_image);
        }
        return null;
    }

    public function availabilityDays()
    {
        return $this->hasMany(EstablishmentAvailabilityDay::class, 'establishment_uuid', 'uuid');
    }
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_establishments',
            'establishment_uuid',
            'user_id',
            'uuid',
            'id'
        )
            ->whereNull('user_establishments.deleted_at')
            ->withPivot(['default', 'sequence', 'uuid'])
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }
    public function owner()
    {
        return $this->belongsTo(Owner::class, 'owner_uuid');
    }
    public function license()
    {
        return $this->belongsTo(License::class, 'license_uuid');
    }
    public function activeActivation()
    {
        return $this->hasOne(EstablishmentActivation::class, 'establishment_uuid', 'uuid')
            ->whereIn('status', [ActivationStatus::PENDING, ActivationStatus::ACTIVE])
            ->latest();
    }
    public static function boot()
    {
        parent::boot();

        $codeHandler = function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (!empty($model->code)) {
                return;
            }

            $prefix = 'EST';
            $yearMonth = now()->format('Ym');

            $query = static::where('code', 'like', $prefix . $yearMonth . '%');

            if ($model->exists && !empty($model->uuid)) {
                $query->where('uuid', '!=', $model->uuid);
            }

            $latestEstablishment = $query->orderBy('code', 'desc')->first();

            if ($latestEstablishment) {
                $lastSequence = intval(substr($latestEstablishment->code, -4));
                $nextSequence = $lastSequence + 1;
            } else {
                $nextSequence = 1;
            }

            $model->code = $prefix . $yearMonth . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
        };

        static::creating($codeHandler);
        static::updating($codeHandler);
    }
}
