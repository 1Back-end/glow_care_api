<?php

namespace Database\Seeders;

use App\Models\Establishment;
use App\Models\EstablishmentAvailabilityDay;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EstablishmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $firstUserId = User::first()?->id ?? 1;

        $establishments = [
            [
                'name'            => 'Institut Élégance Beauté',
                'phone'           => '+237 690000001',
                'second_phone'    => '+237 670000001',
                'email'           => 'contact@elegance-beaute.com',
                'address'         => 'Avenue Kennedy, Centre-ville',
                'city'            => 'Yaoundé',
                'activity_sector' => 'Soins esthétiques et coiffure',
                'is_active'       => true,
            ],
            [
                'name'            => 'Spa & Relax Zen',
                'phone'           => '+237 690000002',
                'second_phone'    => null,
                'email'           => 'zen@sparelax.com',
                'address'         => 'Quartier Bastos',
                'city'            => 'Yaoundé',
                'activity_sector' => 'Spa, Massages et Bien-être',
                'is_active'       => true,
            ],
            [
                'name'            => 'Glamour Ongles & Make-up',
                'phone'           => '+237 690000003',
                'second_phone'    => '+237 670000003',
                'email'           => null,
                'address'         => 'Boulevard de la Liberté, Akwa',
                'city'            => 'Douala',
                'activity_sector' => 'Manucure, Pédicure et Maquillage',
                'is_active'       => true,
            ],
        ];

        $daysOfWeek = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

        foreach ($establishments as $data) {
            $uuid = (string) Str::uuid();

            $establishment = Establishment::create([
                'uuid'            => $uuid,
                'name'            => $data['name'],
                'phone'           => $data['phone'],
                'second_phone'    => $data['second_phone'],
                'email'           => $data['email'],
                'address'         => $data['address'],
                'city'            => $data['city'],
                'activity_sector' => $data['activity_sector'],
                'is_active'       => $data['is_active'],
                'created_by'      => $firstUserId,
                'updated_by'      => $firstUserId,
            ]);

            foreach ($daysOfWeek as $day) {
                $isOpen = ($day !== 'dimanche');

                EstablishmentAvailabilityDay::create([
                    'establishment_uuid' => $uuid,
                    'day_of_week'        => $day,
                    'opening_time'       => $isOpen ? '08:30' : null,
                    'closing_time'       => $isOpen ? '19:00' : null,
                    'is_open'            => $isOpen,
                    'created_by'         => $firstUserId,
                    'updated_by'         => $firstUserId,
                ]);
            }
        }
    }
}
