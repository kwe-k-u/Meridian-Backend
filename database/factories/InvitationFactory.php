<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Company;
use App\Models\User;
use App\Enums\CompanyRole;
use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    public function definition(): array
    {
        return [
            'token' => Str::uuid()->toString(),
            'company_id' => Company::factory(),
            'invited_by' => User::factory(),
            'email' => $this->faker->unique()->safeEmail,
            'role' => $this->faker->randomElement([CompanyRole::ADMIN, CompanyRole::MEMBER]),
            'status' => $this->faker->randomElement(InvitationStatus::cases()),
            'expires_at' => $this->faker->dateTimeBetween('+1 day', '+7 days'),
        ];
    }
}