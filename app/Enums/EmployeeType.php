<?php

namespace App\Enums;

enum EmployeeType: string
{
    case EMPLOYEE = 'employee';
    case INTERN = 'intern';
    case PROFESSIONAL_INTERN = 'professional_intern';
    case APPRENTICE = 'apprentice';
    case CONTRACTOR = 'contractor';
    case CONSULTANT = 'consultant';
    case TEMPORARY = 'temporary';

    public function label(): string
    {
        return match($this) {
            self::EMPLOYEE => 'Employé (CDI/CDD)',
            self::INTERN => 'Stagiaire (Académique)',
            self::PROFESSIONAL_INTERN => 'Stagiaire Professionnel',
            self::APPRENTICE => 'Alternant / Apprenti',
            self::CONTRACTOR => 'Prestataire / indépendant',
            self::CONSULTANT => 'Consultant externe',
            self::TEMPORARY => 'Intérimaire',
        };
    }

    public static function options(): array
    {
        return [
            self::EMPLOYEE->value => self::EMPLOYEE->label(),
            self::INTERN->value => self::INTERN->label(),
            self::PROFESSIONAL_INTERN->value => self::PROFESSIONAL_INTERN->label(),
            self::APPRENTICE->value => self::APPRENTICE->label(),
            self::CONTRACTOR->value => self::CONTRACTOR->label(),
            self::CONSULTANT->value => self::CONSULTANT->label(),
            self::TEMPORARY->value => self::TEMPORARY->label(),
        ];
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
