<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\User;
use App\Enums\AdminRole;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdminFactory extends Factory
{
    protected $model = Admin::class;

    public function definition(): array
    {
        return [
            'admin_id' => 'ADM_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'user_id' => User::factory(),
            'role' => $this->faker->randomElement(AdminRole::cases()),
        ];
    }
}