<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class UserEstablishment extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'user_establishments';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'user_id',
        'establishment_uuid',
        'default',
        'sequence',
    ];

    protected $casts = [
        'default'  => 'boolean',
        'sequence' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
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

    public static function getByUser($userId)
    {
        return self::with([
            'establishment.creator:id,nom_utilisateur',
            'establishment.updater:id,nom_utilisateur',
            'establishment.license',
            'establishment.activeActivation',
            'establishment:uuid,code,reference,name,logo,cover_image,license_uuid',
            'user:id,nom_utilisateur',
        ])
            ->where('user_id', $userId)
            ->get();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->sequence) && !empty($model->user_id)) {
                $maxSequence = static::where('user_id', $model->user_id)->max('sequence');
                $model->sequence = $maxSequence ? $maxSequence + 1 : 1;
            }
        });
    }
}
