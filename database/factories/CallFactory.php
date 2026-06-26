<?php

namespace Database\Factories;

use App\Models\Call;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'call_id' => 'CAL_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'company_id' => $company->company_id,
            'subject' => $this->faker->sentence(6),
            'notes' => $this->faker->optional()->paragraph,
            'scheduled_at' => $this->faker->optional()->dateTime->format('Y-m-d H:i:s'),
            'created_by' => User::factory()->create()->user_id,
            'status' => 'open',
        ];
    }
}
