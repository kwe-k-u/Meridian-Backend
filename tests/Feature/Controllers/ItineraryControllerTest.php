<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Trip;

uses(RefreshDatabase::class);

test('itinerary store validation fails when required fields missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $response = $this->postJson('/api/itinerary', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['trip_id', 'itinerary_name']);
});

test('can create an itinerary and add a day', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    $trip->company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum');

    $payload = [
        'trip_id' => $trip->trip_id,
        'itinerary_name' => 'Test Itinerary',
    ];

    $res = $this->postJson('/api/itinerary', $payload);
    $res->assertStatus(201)->assertJsonStructure(['itinerary_id', 'trip_id', 'itinerary_name']);

    $itineraryId = $res->json('itinerary_id');

    $dayPayload = ['day_number' => 1, 'title' => 'Arrival'];
    $dayRes = $this->postJson("/api/itinerary/{$itineraryId}/days", $dayPayload);
    $dayRes->assertStatus(201)->assertJsonStructure(['itinerary_day_id', 'itinerary_id', 'day_number']);

    $this->assertDatabaseHas('itinerary_days', ['itinerary_id' => $itineraryId, 'day_number' => 1]);
});
