<?php

namespace App\Enums;

enum PaymentMethodEnum: string
{
    case CASH = 'cash';
    case MOMO = 'momo';
    case ORANGE = 'orange';

    public function label(): string
    {
        return match($this) {
            self::CASH => 'Espèces',
            self::MOMO => 'Mobile Money',
            self::ORANGE => 'Orange Money',
        };
    }
}
