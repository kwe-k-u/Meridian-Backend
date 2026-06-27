<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Main database seeder. Creates a test user and then delegates to
 * DemoDataSeeder to populate the full demo dataset.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'display_name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(DemoDataSeeder::class);
    }
}
