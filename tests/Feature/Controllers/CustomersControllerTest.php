<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Company;

uses(RefreshDatabase::class);

test('customers store validation fails when required fields missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $response = $this->postJson('/api/customers', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['company_id', 'first_name', 'last_name']);
});

test('can create a customer when payload is valid', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum');

    $payload = [
        'company_id' => $company->company_id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
    ];

    $response = $this->postJson('/api/customers', $payload);

    $response->assertStatus(201)
        ->assertJsonStructure(['customer_id', 'company_id', 'first_name', 'last_name']);

    $this->assertDatabaseHas('customers', ['company_id' => $company->company_id, 'first_name' => 'Jane']);
});
