<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Owner extends Model
{
    use HasFactory, SoftDeletes,HasUuids;

    protected $table = 'owners';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $primaryKey = 'uuid';

    protected $fillable = [
        'uuid',
        'code',
        'full_name',
        'phone',
        'second_phone',
        'is_whatsapp',
        'email',
        'address',
        'is_active',
        'created_by',
        'updated_by',
        'user_id',
        'cni_file',
        'description',
    ];

    protected $casts = [
        'is_whatsapp' => 'boolean',
        'is_active'   => 'boolean',
    ];

    protected $appends = [
        'public_cni_file',
        'establishments_count'
    ];

    public function getPublicCniFileAttribute()
    {
        $media = $this->medias()->first();
        if ($media) {
            return Storage::disk($media->disk)->url($media->path);
        }
        return null;
    }
    public function getEstablishmentsCountAttribute()
    {
        if ($this->relationLoaded('establishments')) {
            return $this->establishments->count();
        }

        return $this->establishments()->count();
    }

    protected static function boot()
    {
        parent::boot();

        $codeHandler = function ($model) {
            if (!empty($model->code)) {
                return;
            }

            $prefix = 'OWN';
            $yearMonth = now()->format('Ym');

            $query = static::where('code', 'like', $prefix . $yearMonth . '%');

            if ($model->exists && !empty($model->uuid)) {
                $query->where('uuid', '!=', $model->uuid);
            }

            $latestOwner = $query->orderBy('code', 'desc')->first();

            if ($latestOwner) {
                $lastSequence = intval(substr($latestOwner->code, -4));
                $nextSequence = $lastSequence + 1;
            } else {
                $nextSequence = 1;
            }

            $model->code = $prefix . $yearMonth . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
        };

        static::creating($codeHandler);
        static::updating($codeHandler);
    }

    public function establishments()
    {
        return $this->hasMany(Establishment::class, 'owner_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }
}
