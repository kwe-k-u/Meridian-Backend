<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'transaction_id' => 'TRX_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'company_id' => $company->company_id,
            'user_id' => User::factory()->create()->user_id,
            'amount' => $this->faker->randomFloat(2, 1, 1000),
            'currency' => $this->faker->currencyCode,
            'status' => 'pending',
            'description' => $this->faker->optional()->sentence,
        ];
    }
}
