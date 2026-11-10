<?php

namespace App\Models;

use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Client extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    protected $table = 'clients';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'full_name',
        'phone',
        'second_phone',
        'is_watsapp',
        'birth_date',
        'email',
        'address',
        'notes',
        'is_active',
        'created_by',
        'updated_by',
        'code',
        'count_prestations',
        'position',
        'establishment_uuid'
    ];

    protected $casts = [
        'birth_date' => 'date',
        'is_active' => 'boolean',
    ];
    protected $appends = ['client_status', 'client_status_label','current_loyalty_reward'];
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            DB::transaction(function () use ($model) {
                $prefix = 'CL';
                $yearMonth = now()->format('Ym');

                $latestClient = static::where('establishment_uuid', $model->establishment_uuid)
                    ->where('code', 'like', $prefix . $yearMonth . '%')
                    ->orderBy('code', 'desc')
                    ->lockForUpdate()
                    ->first();

                if ($latestClient) {
                    $lastSequence = intval(substr($latestClient->code, -4));
                    $nextSequence = $lastSequence + 1;
                } else {
                    $nextSequence = 1;
                }

                $model->code = $prefix . $yearMonth . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
            });
        });
    }
    public function getMatchedLoyaltyRule()
    {
        $prestations = $this->count_prestations ?? 0;

        if ($prestations <= 0) {
            return null;
        }

        return LoyaltyRule::where('is_active', true)
            ->where('required_prestations', '<=', $prestations)
            ->orderBy('required_prestations', 'desc')
            ->first();
    }
    protected function clientStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                $prestations = $this->count_prestations ?? 0;

                $minRule = LoyaltyRule::where('is_active', true)->min('required_prestations');
                $maxRule = LoyaltyRule::where('is_active', true)->max('required_prestations');
                if (!$minRule || $prestations < $minRule) {
                    return ClientStatus::RAS->value;
                }
                if ($maxRule && $prestations >= $maxRule) {
                    return ClientStatus::VIP->value;
                }
                return ClientStatus::FIDELE->value;
            }
        );
    }

    protected function clientStatusLabel(): Attribute
    {
        return Attribute::make(
            get: function () {
                $statusValue = $this->client_status;
                return ClientStatus::tryFrom($statusValue)?->label() ?? ClientStatus::RAS->label();
            }
        );
    }
    protected function currentLoyaltyReward(): Attribute
    {
        return Attribute::make(
            get: function () {
                $matchedRule = $this->getMatchedLoyaltyRule();

                if (!$matchedRule) {
                    return [
                        'has_reward' => false,
                        'type' => 'none',
                        'label' => 'Aucune',
                        'rule' => null
                    ];
                }

                $isDiscount = $matchedRule->reward_type === 'discount' || $matchedRule->reward_type === \App\Enums\LoyaltyRewardType::DISCOUNT->value;

                $rewardLabel = $isDiscount
                    ? "Réduction de {$matchedRule->discount_percentage}% ({$matchedRule->name})"
                    : "Prestation offerte : {$matchedRule->name}";

                return [
                    'has_reward' => true,
                    'type' => $matchedRule->reward_type,
                    'discount_percentage' => $matchedRule->discount_percentage ?? null,
                    'label' => $rewardLabel,
                    'rule_name' => $matchedRule->name,
                    'required_prestations' => $matchedRule->required_prestations
                ];
            }
        );
    }

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
