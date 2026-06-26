<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => 'USR_' . strtoupper($this->faker->unique()->lexify('????????????')),
            'firebase_uid' => 'fb_' . $this->faker->uuid,
            'email' => $this->faker->unique()->safeEmail,
            'display_name' => $this->faker->name,
            'phone' => $this->faker->e164PhoneNumber,
            'avatar_url' => $this->faker->imageUrl(200, 200, 'people'),
            'status' =>UserStatus::ACTIVE,
            'last_login' => $this->faker->dateTimeThisYear(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
