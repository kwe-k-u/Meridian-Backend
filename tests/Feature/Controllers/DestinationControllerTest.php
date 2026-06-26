<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

uses(RefreshDatabase::class);

test('destination store validation fails without name', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $response = $this->postJson('/api/destinations', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

test('can create destination when payload is valid', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $payload = [
        'name' => 'Accra',
        'country' => 'Ghana',
        'url' => 'https://example.com/accra',
    ];

    $response = $this->postJson('/api/destinations', $payload);

    $response->assertStatus(201)
        ->assertJsonFragment(['name' => 'Accra', 'country' => 'Ghana']);

    $this->assertDatabaseHas('destinations', ['name' => 'Accra']);
});
