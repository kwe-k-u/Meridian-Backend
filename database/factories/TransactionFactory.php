<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'transaction_id' => 'TXN_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'amount' => $this->faker->randomFloat(2, 1, 1000),
            'currency' => $this->faker->randomElement(['GHS', 'USD', 'EUR']),
            'payment_method' => $this->faker->randomElement(['card', 'bank_transfer', 'mobile_money', null]),
            'transaction_reference' => $this->faker->optional()->uuid,
            'status' => $this->faker->randomElement(['pending', 'completed', 'failed', 'refunded']),
            'paid_at' => null,
        ];
    }
}
