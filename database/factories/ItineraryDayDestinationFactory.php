<?php

namespace Database\Factories;

use App\Models\ItineraryDayDestination;
use App\Models\ItineraryDay;
use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItineraryDayDestinationFactory extends Factory
{
    protected $model = ItineraryDayDestination::class;

    public function definition(): array
    {
        $day = ItineraryDay::factory()->create();
        $dest = Destination::factory()->create();

        return [
            'itinerary_day_id' => $day->itinerary_day_id,
            'destination_id' => $dest->destination_id,
            'cost' => (string) $this->faker->optional()->randomFloat(2, 10, 500),
            'currency' => $this->faker->currencyCode,
            'activities' => $this->faker->optional()->sentence,
            'booking_url' => $this->faker->optional()->url,
        ];
    }
}
