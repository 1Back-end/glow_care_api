<?php
namespace App\Enums;

namespace App\Enums;

enum AppointmentOriginEnum: string
{
    case APPOINTMENT = 'appointment';
    case WALK_IN     = 'walk_in';
    case ONLINE      = 'online';

    public function label(): string
    {
        return match($this) {
            self::APPOINTMENT => 'Sur rendez-vous préalable',
            self::WALK_IN     => 'Client sur place (Sans rendez-vous)',
            self::ONLINE      => 'Réservation via le site / Application',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
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
