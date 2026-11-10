<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Prestation;
use App\Models\Establishment;
use App\Enums\PrestationCategory;

class PrestationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $establishment = Establishment::first();

        if (!$establishment) {
            $this->command->info('Aucun établissement trouvé. Veuillez d\'abord exécuter le seeder des établissements.');
            return;
        }

        $prestations = [
            [
                'name'        => 'Massage Relaxant aux Huiles Essentielles',
                'category'    => PrestationCategory::MASSAGE->value,
                'price'       => 25000.00,
                'duration'    => 60,
                'description' => 'Un massage complet du corps pour détendre les muscles, apaiser l\'esprit et évacuer le stress.',
                'is_active'   => true,
            ],
            [
                'name'        => 'Soin Visage Éclat & Hydratation',
                'category'    => PrestationCategory::FACIAL_CARE->value,
                'price'       => 18000.00,
                'duration'    => 45,
                'description' => 'Soin nettoyant, gommage et masque hydratant adapté pour redonner de l\'éclat à votre peau.',
                'is_active'   => true,
            ],
            [
                'name'        => 'Manucure Complète & Soin des mains',
                'category'    => PrestationCategory::MANICURE_PEDICURE->value,
                'price'       => 10000.00,
                'duration'    => 30,
                'description' => 'Soin des mains, entretien des cuticules, limage et pose de vernis soignée.',
                'is_active'   => true,
            ],
            [
                'name'        => 'Brushing & Soin Capillaire',
                'category'    => PrestationCategory::HAIR_STYLIST->value,
                'price'       => 15000.00,
                'duration'    => 45,
                'description' => 'Shampoing traitant, masque nourrissant et brushing professionnel selon vos envies.',
                'is_active'   => true,
            ],
        ];

        foreach ($prestations as $prestationData) {
            Prestation::firstOrCreate(
                [
                    'name'               => $prestationData['name'],
                    'establishment_uuid' => $establishment->uuid,
                ],
                array_merge($prestationData, [
                    'establishment_uuid' => $establishment->uuid,
                ])
            );
        }

        $this->command->info('4 prestations ont été insérées avec succès !');
    }
}
