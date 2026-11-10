<?php

namespace App\Enums;
namespace App\Enums;

enum SubscriptionStatus: string
{
    case PENDING = 'pending';
    case VALIDATED = 'validated';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'En attente',
            self::VALIDATED => 'Validé',
            self::REJECTED => 'Rejeté',
            self::CANCELLED => 'Annulé',
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
