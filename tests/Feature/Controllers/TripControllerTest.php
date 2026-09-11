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

test('trip costs outstanding is based on the confirmed itinerary only, not every draft option summed together', function () {
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
        'trip_name' => 'Multi-Option Trip',
        'status' => 'booked',
    ]);

    $confirmed = Itinerary::create([
        'itinerary_id' => IdGeneratorService::generateId('ITN'),
        'trip_id' => $trip->trip_id,
        'itinerary_name' => 'Option A (confirmed)',
        'status' => 'confirmed',
    ]);
    ItineraryFlight::create([
        'flight_id' => IdGeneratorService::generateId('FLT'),
        'itinerary_id' => $confirmed->itinerary_id,
        'cost' => 1000, 'currency' => 'USD', 'status' => 'pending',
    ]);

    // Two never-accepted draft alternatives — their cost must NOT count toward this trip's
    // outstanding balance, even though they still exist as itinerary rows.
    foreach (['Option B (draft)', 'Option C (draft)'] as $name) {
        $draft = Itinerary::create([
            'itinerary_id' => IdGeneratorService::generateId('ITN'),
            'trip_id' => $trip->trip_id,
            'itinerary_name' => $name,
            'status' => 'draft',
        ]);
        ItineraryFlight::create([
            'flight_id' => IdGeneratorService::generateId('FLT'),
            'itinerary_id' => $draft->itinerary_id,
            'cost' => 5000, 'currency' => 'USD', 'status' => 'pending',
        ]);
    }

    $res = $this->getJson("/api/trips/{$trip->trip_id}/costs");
    $res->assertStatus(200);

    // 1000 flight cost + 5% service fee = 1050, matching the confirmed itinerary's own cost —
    // not 1050 + two more 5250 draft options summed in as well.
    expect((float) $res->json('summary.total_cost'))->toBe(1050.0);
    expect((float) $res->json('summary.outstanding'))->toBe(1050.0);
});

test('trip costs converts payments recorded in a different currency before summing', function () {
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
        'trip_name' => 'Cross-Currency Payment Trip',
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
        'cost' => 1000, 'currency' => 'USD', 'status' => 'pending',
    ]);
    // Total cost: 1000 + 5% fee = 1050 USD.

    // A manually-recorded payment logged in GHS, not USD — must be converted, not summed raw.
    $this->postJson('/api/transactions/trip', [
        'trip_id' => $trip->trip_id,
        'amount' => 1142, // 100 USD at the 11.42 fallback rate
        'currency' => 'GHS',
        'status' => 'completed',
    ])->assertStatus(201);

    $res = $this->getJson("/api/trips/{$trip->trip_id}/costs");
    $res->assertStatus(200);

    expect((float) $res->json('summary.total_cost'))->toBe(1050.0);
    expect($res->json('summary.currency'))->toBe('USD');
    expect((float) $res->json('summary.total_paid'))->toBe(100.0);
    expect((float) $res->json('summary.outstanding'))->toBe(950.0);
});
