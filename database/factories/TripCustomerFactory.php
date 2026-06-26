<?php

namespace Database\Factories;

use App\Models\TripCustomer;
use App\Models\Trip;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class TripCustomerFactory extends Factory
{
    protected $model = TripCustomer::class;

    public function definition(): array
    {
        $trip = Trip::factory()->create();
        $customer = Customer::factory()->create();

        return [
            'trip_id' => $trip->trip_id,
            'customer_id' => $customer->customer_id,
            'role' => 'traveler',
        ];
    }
}
