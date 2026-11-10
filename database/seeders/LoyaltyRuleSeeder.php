<?php

namespace Database\Seeders;

use App\Models\LoyaltyRule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LoyaltyRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultUser = User::first();
        $userId = $defaultUser ? $defaultUser->id : null;

        LoyaltyRule::truncate();

        $rules = [
            [
                'name' => 'Petite attention (5 prestations)',
                'required_prestations' => 5,
                'reward_type' => 'discount',
                'discount_percentage' => 10.00,
            ],
            [
                'name' => 'Réduction fidélité classique (10 prestations)',
                'required_prestations' => 10,
                'reward_type' => 'discount',
                'discount_percentage' => 15.00,
            ],
            [
                'name' => 'Super Réduction (15 prestations)',
                'required_prestations' => 15,
                'reward_type' => 'discount',
                'discount_percentage' => 25.00,
            ],
            [
                'name' => 'Prestation offerte (20 prestations)',
                'required_prestations' => 20,
                'reward_type' => 'free_service',
                'discount_percentage' => null,
            ],
            [
                'name' => 'Cadeau VIP Ultime (30 prestations)',
                'required_prestations' => 30,
                'reward_type' => 'free_service',
                'discount_percentage' => null,
            ],
        ];

        foreach ($rules as $rule) {
            LoyaltyRule::create([
                'uuid' => (string) Str::uuid(),
                'name' => $rule['name'],
                'required_prestations' => $rule['required_prestations'],
                'reward_type' => $rule['reward_type'],
                'discount_percentage' => $rule['discount_percentage'],
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }
    }
}
