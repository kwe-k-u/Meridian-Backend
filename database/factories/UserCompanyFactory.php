<?php

namespace Database\Factories;

use App\Models\UserCompany;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Enums\CompanyRole;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserCompany>
 */
class UserCompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => Str::random(12),
            'company_id' => Str::random(12),
            'role' => CompanyRole::MEMBER->value,
            'is_default' => false,
            'is_enabled' => true,
            'joined_at' => now(),
        ];
    }
}
