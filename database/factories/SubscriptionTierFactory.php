<?php

namespace Database\Factories;

use App\Models\SubscriptionTier;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionTierFactory extends Factory
{
    protected $model = SubscriptionTier::class;

    public function definition(): array
    {
        return [
            'tier_id' => 'SUB_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'name' => $this->faker->randomElement(['Starter', 'Growth', 'Enterprise']),
            'price_quarterly' => $this->faker->randomElement([1500, 4500, 12000]), // Int representing currency units
            'features' => [
                'analytics' => $this->faker->boolean,
                'max_users' => $this->faker->numberBetween(5, 50),
                'api_access' => $this->faker->boolean,
            ],
            'status' => true,
        ];
    }
}