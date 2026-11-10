<?php

namespace App\Enums;

enum PrestationCategory: string
{
    case FACIAL_CARE = 'facial_care';
    case BODY_CARE = 'body_care';
    case MASSAGE = 'massage';
    case HAIR_REMOVAL = 'hair_removal';
    case HAIR_STYLIST = 'hair_stylist';
    case MANICURE_PEDICURE = 'manicure_pedicure';

    public static function options(): array
    {
        return [
            self::FACIAL_CARE->value       => 'Soins visage',
            self::BODY_CARE->value         => 'Soins corps',
            self::MASSAGE->value           => 'Massage',
            self::HAIR_REMOVAL->value      => 'Épilation',
            self::HAIR_STYLIST->value      => 'Coiffure',
            self::MANICURE_PEDICURE->value => 'Manucure & Pédicure',
        ];
    }

    public function label(): string
    {
        return match($this) {
            self::FACIAL_CARE       => 'Soins visage',
            self::BODY_CARE         => 'Soins corps',
            self::MASSAGE           => 'Massage',
            self::HAIR_REMOVAL      => 'Épilation',
            self::HAIR_STYLIST      => 'Coiffure',
            self::MANICURE_PEDICURE => 'Manucure & Pédicure',
        };
    }
    public static function safeLabel(self|string|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof self) {
            return $value->label();
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
