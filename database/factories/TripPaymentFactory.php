<?php

namespace Database\Factories;

use App\Models\TripPayment;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TripPaymentFactory extends Factory
{
    protected $model = TripPayment::class;

    public function definition(): array
    {
        $trip = Trip::factory()->create();

        return [
            'trip_payment_id' => 'TPY_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'trip_id' => $trip->trip_id,
            'paid_by' => User::factory()->create()->user_id,
            'amount' => $this->faker->randomFloat(2, 10, 5000),
            'currency' => $this->faker->currencyCode,
            'status' => 'pending',
            'transaction_reference' => $this->faker->optional()->uuid,
        ];
    }
}
