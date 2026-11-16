<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory, SoftDeletes,HasUuids;

    protected $table = 'payments';
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'establishment_uuid',
        'invoices_uuid',
        'amount',
        'payment_method',
        'phone',
        'gateway',
        'reference',
        'comment',
        'reason',
        'status',
        'gateway_response',
        'created_by',
        'updated_by',
        'regulation_methods_uuid',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'gateway_response' => 'array',
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoices_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function regulation_methods()
    {
        return $this->belongsTo(RegulationMethod::class, 'regulation_methods_uuid', 'uuid');
    }
    public function cancellor()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
