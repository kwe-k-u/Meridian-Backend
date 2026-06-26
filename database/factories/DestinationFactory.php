<?php

namespace Database\Factories;

use App\Models\Destination;
use Illuminate\Database\Eloquent\Factories\Factory;

class DestinationFactory extends Factory
{
    protected $model = Destination::class;

    public function definition(): array
    {
        return [
            'destination_id' => 'DST_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'name' => $this->faker->city,
            'country' => $this->faker->country,
            'url' => $this->faker->optional()->url,
            'created_at' => now(),
        ];
    }
}
