<?php

namespace Database\Factories;

use App\Models\CompanySubscription;
use App\Models\Company;
use App\Models\SubscriptionTier;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanySubscriptionFactory extends Factory
{
    protected $model = CompanySubscription::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeThisYear();
        $endDate = (clone $startDate)->modify('+90 days'); // Quarterly cycle

        return [
            'subscription_id' => 'CSB_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'company_id' => Company::factory(),
            'tier_id' => SubscriptionTier::factory(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $this->faker->randomElement(SubscriptionStatus::cases()),
        ];
    }
}