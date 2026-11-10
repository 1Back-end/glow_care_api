<?php

namespace App\Models;

use App\Enums\PrestationCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\Storage;

class Prestation extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'prestations';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'establishment_uuid',
        'name',
        'category',
        'price',
        'duration',
        'description',
        'file',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration' => 'integer',
        'is_active' => 'boolean',
        'category'  => PrestationCategory::class,
    ];
    protected $appends = ['public_file','public_category_label','public_duration_label'];

    public function getPublicFileAttribute()
    {
        if ($this->file) {
            return Storage::disk('public')->url($this->file);
        }
        return null;
    }

    public function getPublicCategoryLabelAttribute(): string
    {
        return PrestationCategory::safeLabel($this->category);
    }
    public function getPublicDurationLabelAttribute(): string
    {
        $minutes = $this->duration ?? 0;

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return "{$hours}h";
        }

        return "{$hours}h " . ($remainingMinutes < 10 ? "0" : "") . "{$remainingMinutes} min";
    }
    public function medias(): MorphMany
    {
        return $this->morphMany(Medias::class, 'mediable');
    }

    // Relations
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
}
