<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::insert([
            [
                'login' => 'glow.admin',
                'nom_utilisateur' => 'Gérante Glow Care',
                'password' => Hash::make('password'),
                'password_expiated_at' => now()->addMonths(3),
                'email' => 'admin@glowcare.com',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'login' => 'glow.estheticienne',
                'nom_utilisateur' => 'Sarah Esthétique',
                'password' => Hash::make('password'),
                'password_expiated_at' => now()->addMonths(3),
                'email' => 'sarah@glowcare.com',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
