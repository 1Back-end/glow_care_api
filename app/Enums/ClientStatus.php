<?php

namespace App\Enums;

enum ClientStatus: string
{
    case RAS = 'ras';
    case FIDELE = 'fidele';
    case VIP = 'vip';

    public function label(): string
    {
        return match($this) {
            self::RAS => 'Nouveau',
            self::FIDELE => 'Fidèle',
            self::VIP => 'VIP',
        };
    }

    public static function safeLabel(?string $value): string
    {
        if ($value === null) {
            return 'Inconnu';
        }

        return self::tryFrom($value)?->label() ?? 'Inconnu';
    }

    public static function toArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
