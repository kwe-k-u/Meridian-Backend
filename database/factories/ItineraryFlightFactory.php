<?php

namespace Database\Factories;

use App\Models\ItineraryFlight;
use App\Models\Itinerary;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItineraryFlightFactory extends Factory
{
    protected $model = ItineraryFlight::class;

    public function definition(): array
    {
        $itinerary = Itinerary::factory()->create();

        return [
            'itinerary_flight_id' => 'FLG_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'itinerary_id' => $itinerary->itinerary_id,
            'flight_number' => strtoupper($this->faker->bothify('??###')),
            'departure_airport' => $this->faker->airportCode,
            'arrival_airport' => $this->faker->airportCode,
            'departure_time' => $this->faker->optional()->dateTime->format('Y-m-d H:i:s'),
            'arrival_time' => $this->faker->optional()->dateTime->format('Y-m-d H:i:s'),
            'status' => 'pending',
            'notes' => $this->faker->optional()->sentence,
        ];
    }
}
