<?php

namespace App\Enums;

enum CashRegisterType: string
{
    case MAIN = 'main';
    case INDIVIDUAL = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::MAIN => 'Caisse principale',
            self::INDIVIDUAL => 'Caisse individuelle',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
