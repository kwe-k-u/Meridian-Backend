<?php

namespace Database\Factories;

use App\Models\ItineraryDay;
use App\Models\Itinerary;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItineraryDayFactory extends Factory
{
    protected $model = ItineraryDay::class;

    public function definition(): array
    {
        $itinerary = Itinerary::factory()->create();

        return [
            'itinerary_day_id' => 'ITD_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'itinerary_id' => $itinerary->itinerary_id,
            'day_number' => $this->faker->numberBetween(1, 10),
            'date' => $this->faker->optional()->date(),
            'title' => $this->faker->optional()->sentence(4),
            'description' => $this->faker->optional()->paragraph,
            'location' => $this->faker->optional()->city,
        ];
    }
}
