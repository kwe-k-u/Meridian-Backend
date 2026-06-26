<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Trip;

uses(RefreshDatabase::class);

test('subscription transaction validation fails when required fields missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson('/api/transactions/subscription', []);
    $res->assertStatus(422)->assertJsonValidationErrors(['company_id', 'subscription_id', 'amount']);
});

test('can record a subscription transaction', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $sub = CompanySubscription::factory()->create(['company_id' => $company->company_id]);

    $this->actingAs($user, 'sanctum');

    $payload = [
        'company_id' => $company->company_id,
        'subscription_id' => $sub->subscription_id,
        'amount' => 1000,
        'currency' => 'GHS',
    ];

    $res = $this->postJson('/api/transactions/subscription', $payload);
    $res->assertStatus(201)->assertJsonStructure(['transaction_id', 'amount']);

    $this->assertDatabaseHas('subscription_payments', ['subscription_id' => $sub->subscription_id]);
});

test('can record a trip transaction', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();

    $this->actingAs($user, 'sanctum');

    $payload = ['trip_id' => $trip->trip_id, 'amount' => 500, 'currency' => 'GHS'];
    $res = $this->postJson('/api/transactions/trip', $payload);
    $res->assertStatus(201)->assertJsonStructure(['transaction_id', 'amount']);

    $this->assertDatabaseHas('trip_payments', ['trip_id' => $trip->trip_id]);
});
