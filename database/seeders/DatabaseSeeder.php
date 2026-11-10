<?php
namespace Database\Seeders;

use App\Http\Controllers\Api\V1\ClientController;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {

        $this->call([
            InitBDForAllDataSeeder::class,
            CountriesTableSeeder::class,
            ClientSeeder::class,
            LoyaltyRuleSeeder::class,
            EstablishmentSeeder::class,
            OwnerSeeder::class,
            PrestationSeeder::class,
            AppointmentSeeder::class,
        ]);
    }
}
