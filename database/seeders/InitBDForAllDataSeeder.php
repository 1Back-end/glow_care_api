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
        $roleSuperAdmin = Role::updateOrCreate(
            ['name' => 'SUPER_ADMIN', 'guard_name' => 'web'],
            ['is_super_admin' => true, 'is_client' => false]
        );

        $roleClientAdmin = Role::updateOrCreate(
            ['name' => 'ADMINISTRATEUR', 'guard_name' => 'web'],
            ['is_super_admin' => false, 'is_client' => true]
        );

        $superAdmin = User::updateOrCreate(
            ['login' => 'glow.admin'],
            [
                'nom_utilisateur' => 'Gérante Glow Care',
                'email'           => 'admin@glowcare.com',
                'password'        => $defaultPassword,
                'is_super_admin'  => true,
                'is_client'       => false,
                'is_sub_user'     => false,
            ]
        );
        $superAdmin->syncRoles([$roleSuperAdmin->name]);

        $clientAdmin = User::updateOrCreate(
            ['login' => 'glow.client'],
            [
                'nom_utilisateur' => 'Awa Marie',
                'email'           => 'awa@glowcare.com',
                'password'        => $defaultPassword,
                'is_super_admin'  => false,
                'is_client'       => true,
                'is_sub_user'     => false,
            ]
        );
        $clientAdmin->syncRoles([$roleClientAdmin->name]);

        $this->command->info('Seeder Glow Care : 2 utilisateurs configurés avec succès !');
    }
}
