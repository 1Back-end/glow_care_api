<?php

namespace App\Enums;

enum TransactionType: string
{
    case INCOME = 'income';
    case EXPENSE = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::INCOME => 'Entrée',
            self::EXPENSE => 'Sortie',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::INCOME => 'success',
            self::EXPENSE => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::INCOME => 'bi-arrow-down-short',
            self::EXPENSE => 'bi-arrow-up-short',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(
            fn (self $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'icon' => $type->icon(),
                'color' => $type->badgeColor(),
            ],
            self::cases()
        );
    }
}
