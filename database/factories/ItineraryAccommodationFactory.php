<?php

namespace Database\Factories;

use App\Models\ItineraryAccommodation;
use App\Models\Itinerary;
use Illuminate\Database\Eloquent\Factories\Factory;

class ItineraryAccommodationFactory extends Factory
{
    protected $model = ItineraryAccommodation::class;

    public function definition(): array
    {
        $itinerary = Itinerary::factory()->create();

        return [
            'itinerary_accommodation_id' => 'ACC_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'itinerary_id' => $itinerary->itinerary_id,
            'name' => $this->faker->company,
            'address' => $this->faker->address,
            'check_in' => $this->faker->optional()->dateTime->format('Y-m-d H:i:s'),
            'check_out' => $this->faker->optional()->dateTime->format('Y-m-d H:i:s'),
            'status' => 'pending',
            'notes' => $this->faker->optional()->sentence,
        ];
    }
}
