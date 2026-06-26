<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'customer_id' => 'CST_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'company_id' => $company->company_id,
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'email' => $this->faker->unique()->safeEmail,
            'phone' => $this->faker->optional()->e164PhoneNumber,
            'nationality' => $this->faker->optional()->country,
            'date_of_birth' => $this->faker->optional()->date(),
            'passport_number' => $this->faker->optional()->bothify('??######'),
            'notes' => $this->faker->optional()->sentence,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
