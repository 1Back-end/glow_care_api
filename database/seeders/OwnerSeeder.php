<?php

namespace Database\Seeders;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ownersData = [
            [
                'full_name'    => 'Vanessa Fotso',
                'email'        => 'vanessa.fotso@beautyspa.cm',
                'phone'        => '+237691234567',
                'second_phone' => '+237671234567',
                'address'      => 'Bastos, Yaoundé',
                'description'  => 'Propriétaire de l’institut de beauté et spa "Espace Zen & Esthétique".',
            ],
            [
                'full_name'    => 'Diane Mbatchou',
                'email'        => 'diane.mbatchou@glowstudio.cm',
                'phone'        => '+237692345678',
                'second_phone' => '+237672345678',
                'address'      => 'Bonapriso, Douala',
                'description'  => 'Fondatrice du salon de soins corporels et onglerie "Glow & Glamour".',
            ],
            [
                'full_name'    => 'Carine Tientcheu',
                'email'        => 'carine.tientcheu@essentielbeaute.cm',
                'phone'        => '+237693456789',
                'second_phone' => '+237673456789',
                'address'      => 'Omnisport, Yaoundé',
                'description'  => 'Gérante de l’institut spécialisé en soins du visage et épilation.',
            ],
            [
                'full_name'    => 'Nathalie Etoa',
                'email'        => 'nathalie.etoa@paradisdetente.cm',
                'phone'        => '+237694567890',
                'second_phone' => '+237674567890',
                'address'      => 'Akwa, Douala',
                'description'  => 'Propriétaire du centre de bien-être, massages relaxants et hammam.',
            ],
            [
                'full_name'    => 'Sandrine Kouam',
                'email'        => 'sandrine.kouam@beautylounge.cm',
                'phone'        => '+237695678901',
                'second_phone' => '+237675678901',
                'address'      => 'Melen, Yaoundé',
                'description'  => 'Propriétaire de l’institut de beauté polyvalent et relooking.',
            ],
        ];

        foreach ($ownersData as $data) {
            // Génération automatique du login et du prénom
            $login = strtolower(explode('@', $data['email'])[0]);
            $nameParts = explode(' ', trim($data['full_name']), 2);
            $prenom = $nameParts[0] ?? null;

            // 1. Création du compte utilisateur associé
            $user = User::create([
                'login'           => $login,
                'email'           => $data['email'],
                'password'        => Hash::make('password123'),
                'nom_utilisateur' => $data['full_name'],
                'prenom'          => $prenom,
            ]);

            Owner::create([
                'full_name'    => $data['full_name'],
                'phone'        => $data['phone'],
                'second_phone' => $data['second_phone'],
                'is_whatsapp'  => true,
                'email'        => $data['email'],
                'address'      => $data['address'],
                'description'  => $data['description'],
                'is_active'    => true,
                'user_id'      => $user->id,
                'created_by'   => $user->id,
                'updated_by'   => $user->id,
            ]);
        }
    }
}
