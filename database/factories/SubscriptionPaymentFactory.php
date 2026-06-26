<?php

namespace Database\Factories;

use App\Models\SubscriptionPayment;
use App\Models\CompanySubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPaymentFactory extends Factory
{
    protected $model = SubscriptionPayment::class;

    public function definition(): array
    {
        $sub = CompanySubscription::factory()->create();

        return [
            'subscription_payment_id' => 'SPY_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'company_subscription_id' => $sub->company_subscription_id,
            'paid_by' => User::factory()->create()->user_id,
            'amount' => $this->faker->randomFloat(2, 1, 10000),
            'currency' => $this->faker->currencyCode,
            'status' => 'pending',
            'transaction_reference' => $this->faker->optional()->uuid,
        ];
    }
}
