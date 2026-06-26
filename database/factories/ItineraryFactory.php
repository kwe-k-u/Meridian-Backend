<?php

namespace Database\Factories;

use App\Models\Itinerary;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItineraryFactory extends Factory
{
    protected $model = Itinerary::class;

    public function definition(): array
    {
        $trip = Trip::factory()->create();

        return [
            'itinerary_id' => 'ITN_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'trip_id' => $trip->trip_id,
            'created_by' => User::factory()->create()->user_id,
            'itinerary_name' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph,
            'start_date' => $this->faker->optional()->date(),
            'end_date' => $this->faker->optional()->date(),
            'status' => 'draft',
        ];
    }
}
