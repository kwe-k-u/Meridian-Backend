<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Trip;

uses(RefreshDatabase::class);

test('dashboard latest_trips returns plain YYYY-MM-DD dates, not full ISO datetimes', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create([
        'start_date' => '2026-09-10',
        'end_date' => '2026-09-15',
    ]);
    $trip->company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $res = $this->getJson('/api/dashboard');

    $res->assertOk();
    $latestTrip = collect($res->json('latest_trips'))->firstWhere('trip_id', $trip->trip_id);
    expect($latestTrip['start_date'])->toBe('2026-09-10');
    expect($latestTrip['end_date'])->toBe('2026-09-15');
});
