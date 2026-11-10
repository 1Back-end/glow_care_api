<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LoyaltyRule extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'loyalty_rules';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'name',
        'required_prestations',
        'reward_type',
        'discount_percentage',
        'is_active',
        'created_by',
        'updated_by',
        'establishment_uuid'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'discount_percentage' => 'decimal:2',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid');
    }
}
