<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class InitBDForAllDataSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPassword = Hash::make('1234567');

        $roleAdmin  = Role::updateOrCreate(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
        $roleStaff  = Role::updateOrCreate(['name' => 'ESTHETICIENNE', 'guard_name' => 'web']);
        $roleClient = Role::updateOrCreate(['name' => 'CLIENT', 'guard_name' => 'web']);

        $admin = User::updateOrCreate(
            ['login' => 'glow.admin'],
            [
                'nom_utilisateur' => 'Gérante Glow Care',
                'email'           => 'admin@glowcare.com',
                'password'        => $defaultPassword,
                'is_super_admin'  => true, // Uniquement pour le super admin
            ]
        );
        $admin->syncRoles([$roleAdmin->name]);

        $staff = User::updateOrCreate(
            ['login' => 'glow.estheticienne'],
            [
                'nom_utilisateur' => 'Sarah Esthétique',
                'email'           => 'sarah@glowcare.com',
                'password'        => $defaultPassword,
            ]
        );
        $staff->syncRoles([$roleStaff->name]);

        $client = User::updateOrCreate(
            ['login' => 'glow.client'],
            [
                'nom_utilisateur' => 'Awa Marie',
                'email'           => 'awa@glowcare.com',
                'password'        => $defaultPassword,
            ]
        );
        $client->syncRoles([$roleClient->name]);

        $this->command->info('Seeder Glow Care : 3 utilisateurs créés avec succès !');
    }
}
