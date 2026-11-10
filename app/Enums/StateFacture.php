<?php

namespace App\Enums;

enum StateFacture: int
{
    case CREATE = 1;
    case IN_PROGRESS = 2;
    case PAID = 3;
    case CANCELLED = 4;

    public function label(): string
    {
        return match ($this) {
            self::CREATE => 'Créé',
            self::IN_PROGRESS => 'En cours',
            self::PAID => 'Soldé',
            self::CANCELLED => 'Annulée',
        };
    }

    public static function safeLabel(self|int|string|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof self) {
            return $value->label();
        }

        $enum = self::tryFrom((int)$value);
        return $enum?->label() ?? '';
    }

    public static function toArray(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn($case) => [$case->value => $case->label()])
            ->toArray();
    }
}
