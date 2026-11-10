<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Appointment extends Model
{
    use HasFactory, SoftDeletes,HasUuids;
    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';


    protected $fillable = [
        'uuid',
        'code',
        'title',
        'establishment_uuid',
        'client_uuid',
        'prestation_uuid',
        'date_rdv',
        'status',
        'price',
        'notes',
        'created_by',
        'updated_by',
        'reason_rejected',
        'rejected_by',
        'rejected_at',
        'validated_by',
        'validated_at'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'rejected_at' => 'datetime',
        'status'     => AppointmentStatus::class,
    ];

    protected $appends = ['status_label'];

    public function getStatusLabelAttribute(): string
    {
        return AppointmentStatus::safeLabel($this->status);
    }

    protected static function boot()
    {
        parent::boot();
        $codeHandler = function ($model) {
            if (!empty($model->code)) {
                return;
            }

            $prefix = 'RDV';
            $yearMonth = now()->format('Ym');

            $query = static::where('code', 'like', $prefix . $yearMonth . '%');

            if ($model->exists && !empty($model->uuid)) {
                $query->where('uuid', '!=', $model->uuid);
            }

            $latestAppointment = $query->orderBy('code', 'desc')->first();

            if ($latestAppointment) {
                $lastSequence = intval(substr($latestAppointment->code, -4));
                $nextSequence = $lastSequence + 1;
            } else {
                $nextSequence = 1;
            }

            $model->code = $prefix . $yearMonth . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
        };

        static::creating($codeHandler);
        static::updating($codeHandler);
    }

    // Relations
    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'establishment_uuid', 'uuid');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_uuid');
    }

    public function prestation()
    {
        return $this->belongsTo(Prestation::class, 'prestation_uuid', 'uuid');
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
