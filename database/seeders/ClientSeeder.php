<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        $prenoms = ['Marie', 'Alphonse', 'Fatou', 'Aïcha', 'Laurent', 'Jean', 'Chantal', 'Patrick', 'Sandrine', 'Christophe', 'Divine', 'Junior', 'Grace', 'Emmanuel', 'Priscille'];
        $noms = ['Dupont', 'Kamara', 'Ndoba', 'Mballa', 'Talla', 'Kouassi', 'Nkomo', 'Fofana', 'Biloa', 'Tagne', 'Etoa', 'Sango', 'Mengue', 'Mbarga', 'Oumar'];

        // Vider la table proprement
        Client::truncate();

        $prefix = 'CL' . now()->format('Ym');

        for ($i = 1; $i <= 5; $i++) {
            $prenom = $prenoms[array_rand($prenoms)];
            $nom = $noms[array_rand($noms)];
            $fullName = $prenom . ' ' . $nom;

            $customCode = $prefix . str_pad($i, 4, '0', STR_PAD_LEFT);

            Client::create([
                'uuid' => (string) Str::uuid(),
                'code' => $customCode,
                'full_name' => $fullName,
                'phone' => '+2376' . rand(50, 99) . rand(10, 99) . rand(10, 99) . rand(10, 99),
                'second_phone' => rand(0, 1) ? '+2376' . rand(50, 99) . rand(10, 99) . rand(10, 99) . rand(10, 99) : null,
                'is_watsapp' => (bool) rand(0, 1),
                'birth_date' => now()->subYears(rand(18, 55))->subDays(rand(0, 365))->format('Y-m-d'),
                'email' => strtolower($prenom . '.' . $nom . rand(10, 99) . '@example.com'),
                'address' => 'Yaoundé, Quartier ' . ['Mvan', 'Biyem-Assi', 'Ngousso', 'Mimboman', 'Bastos'][rand(0, 4)],
                'notes' => rand(0, 1) ? 'Client régulier de l’institut.' : null,
                'is_active' => true,
                'count_prestations' => rand(0, 15),
                'position' => $i,
                'created_by' => 1,
                'updated_by' => 1,
            ]);
        }
    }
}
