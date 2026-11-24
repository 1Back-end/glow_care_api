<?php

namespace App\Enums;

enum CashRegisterStatus: string
{
    case OPEN = 'open';
    case PAUSED = 'paused';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Ouvert',
            self::PAUSED => 'En pause',
            self::CLOSED => 'Fermé',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
