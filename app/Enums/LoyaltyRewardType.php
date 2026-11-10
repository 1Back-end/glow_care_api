<?php

namespace App\Enums;

enum LoyaltyRewardType: string
{
    case DISCOUNT = 'discount';
    case FREE_SERVICE = 'free_service';

    public function label(): string
    {
        return match($this) {
            self::DISCOUNT => 'Réduction (%)',
            self::FREE_SERVICE => 'Prestation offerte',
        };
    }

    public static function safeLabel(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return self::tryFrom($value)?->label() ?? '';
    }

    public static function toArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
