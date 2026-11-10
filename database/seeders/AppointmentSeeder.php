<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Prestation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class AppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $establishment = Establishment::first();

        if (!$establishment) {
            $this->command->info('⚠️ Veuillez d\'abord insérer au moins un établissement.');
            return;
        }

        if (Client::count() === 0 || Prestation::count() === 0) {
            $this->command->info('⚠️ Veuillez d\'abord insérer des clients et des prestations avant de lancer ce seeder.');
            return;
        }

        $appointments = [
            [
                'title'      => 'Soin visage - Marie Dupont',
                'start_time' => Carbon::now()->setHour(9)->setMinute(0)->setSecond(0),
                'end_time'   => Carbon::now()->setHour(9)->setMinute(50)->setSecond(0),
                'status'     => 'confirmed',
                'price'      => 20000.00,
                'notes'      => 'Première visite',
            ],
            [
                'title'      => 'Manucure - Aicha Ndiaye',
                'start_time' => Carbon::now()->addDay()->setHour(11)->setMinute(0)->setSecond(0),
                'end_time'   => Carbon::now()->addDay()->setHour(11)->setMinute(30)->setSecond(0),
                'status'     => 'pending',
                'price'      => 10000.00,
                'notes'      => 'Pose de vernis simple',
            ],
            [
                'title'      => 'Pédicure - Sandra Tchoua',
                'start_time' => Carbon::now()->addDay()->setHour(14)->setMinute(0)->setSecond(0),
                'end_time'   => Carbon::now()->addDay()->setHour(14)->setMinute(45)->setSecond(0),
                'status'     => 'confirmed',
                'price'      => 12500.00,
                'notes'      => 'Soin complet des pieds',
            ],
            [
                'title'      => 'Massage - Fatou Kamara',
                'start_time' => Carbon::now()->addDays(2)->setHour(16)->setMinute(0)->setSecond(0),
                'end_time'   => Carbon::now()->addDays(2)->setHour(17)->setMinute(0)->setSecond(0),
                'status'     => 'confirmed',
                'price'      => 25000.00,
                'notes'      => 'Massage relaxant aux huiles',
            ],
            [
                'title'      => 'Soin visage - Julie Mbia',
                'start_time' => Carbon::now()->addDays(3)->setHour(10)->setMinute(0)->setSecond(0),
                'end_time'   => Carbon::now()->addDays(3)->setHour(10)->setMinute(50)->setSecond(0),
                'status'     => 'pending',
                'price'      => 20000.00,
                'notes'      => 'Peau sensible',
            ],
        ];

        foreach ($appointments as $data) {
            $randomClient = Client::inRandomOrder()->first();
            $randomPrestation = Prestation::inRandomOrder()->first();
            $randomStaff = User::inRandomOrder()->first();

            if (!$randomClient || !$randomPrestation) {
                continue;
            }

            Appointment::create([
                'establishment_uuid' => $establishment->uuid,
                'client_uuid'        => $randomClient->uuid,
                'prestation_uuid'    => $randomPrestation->uuid,
                'staff_id'           => $randomStaff?->id,
                'title'              => $data['title'],
                'start_time'         => $data['start_time'],
                'end_time'           => $data['end_time'],
                'status'             => $data['status'],
                'price'              => $data['price'],
                'notes'              => $data['notes'],
                'created_by'         => $randomStaff?->id,
            ]);
        }

        $this->command->info('✅ 5 rendez-vous de test avec des clients et prestations aléatoires ont été insérés avec succès !');
    }
}
