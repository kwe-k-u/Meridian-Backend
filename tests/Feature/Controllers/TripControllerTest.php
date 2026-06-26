<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Company;

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
