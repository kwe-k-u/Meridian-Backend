<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Company;
use App\Models\Trip;
use App\Models\Itinerary;
use App\Models\ItineraryFlight;
use App\Services\IdGeneratorService;

uses(RefreshDatabase::class);

test('trip store validation fails when required fields missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $response = $this->postJson('/api/trips', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['company_id', 'trip_name']);
});

test('can create a trip when payload is valid', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user, 'sanctum');

    $payload = [
        'company_id' => $company->company_id,
        'trip_name' => 'Integration Test Trip',
        'country' => 'Ghana',
    ];

    $response = $this->postJson('/api/trips', $payload);

    $response->assertStatus(201)
        ->assertJsonStructure(['trip_id', 'company_id', 'trip_name']);

    $this->assertDatabaseHas('trips', ['company_id' => $company->company_id, 'trip_name' => 'Integration Test Trip']);
});

test('trip list shows the confirmed itinerary\'s real cost, not the stale budget estimate', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $trip = Trip::create([
        'trip_id' => IdGeneratorService::generateId('TRP'),
        'company_id' => $company->company_id,
        'created_by' => $user->user_id,
        'trip_name' => 'Budget Mismatch Trip',
        'budget' => 8000, // a free-text estimate typed in at creation — should NOT be shown
        'status' => 'booked',
    ]);
    $itinerary = Itinerary::create([
        'itinerary_id' => IdGeneratorService::generateId('ITN'),
        'trip_id' => $trip->trip_id,
        'itinerary_name' => 'Option A',
        'status' => 'confirmed',
    ]);
    ItineraryFlight::create([
        'flight_id' => IdGeneratorService::generateId('FLT'),
        'itinerary_id' => $itinerary->itinerary_id,
        'cost' => 1000,
        'currency' => 'USD',
        'status' => 'pending',
    ]);

    $res = $this->getJson('/api/trips');
    $res->assertStatus(200);

    $row = collect($res->json('data'))->firstWhere('trip_id', $trip->trip_id);
    expect($row)->not->toBeNull();
    // 1000 flight cost + 5% service fee = 1050 — not the 8000 budget estimate.
    expect((float) $row['computed_total'])->toBe(1050.0);
    expect($row['computed_currency'])->toBe('USD');
    expect($row)->not->toHaveKey('itineraries');
});
