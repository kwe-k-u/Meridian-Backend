<?php

namespace Database\Factories;

use App\Models\Company;
use App\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'company_id' => 'CMP_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'company_name' => $this->faker->company,
            'city_of_operation' => $this->faker->city,
            'status' => $this->faker->boolean(0.5),
        ];
    }
}