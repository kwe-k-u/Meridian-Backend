<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TripFactory extends Factory
{
    protected $model = Trip::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'trip_id' => 'TRP_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'company_id' => $company->company_id,
            'created_by' => User::factory()->create()->user_id,
            'trip_name' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->paragraph,
            'start_date' => $this->faker->optional()->date(),
            'end_date' => $this->faker->optional()->date(),
            'budget' => $this->faker->optional()->randomNumber(5, true),
            'status' => 'inquiry',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
