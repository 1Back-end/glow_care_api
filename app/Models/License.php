<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class License extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'licenses';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'license_key',
        'signature',
        'payload',
        'duration_value',
        'duration_unit',
        'starts_at',
        'expires_at',
        'is_active',
        'created_by',
        'updated_by',
        'price'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'payload' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
    protected $hidden = [
        'signature',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
