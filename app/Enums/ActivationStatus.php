<?php

namespace App\Enums;

enum ActivationStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case SUSPENDED = 'suspended';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'En attente d\'activation',
            self::ACTIVE => 'Actif',
            self::EXPIRED => 'Expiré',
            self::SUSPENDED => 'Suspendu',
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
