<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Trip;

uses(RefreshDatabase::class);

test('calls store validation fails when required fields missing', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $response = $this->postJson('/api/calls', []);

    $response->assertStatus(422)->assertJsonValidationErrors(['trip_id']);
});

test('can create a call and add an action item', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();

    $this->actingAs($user, 'sanctum');

    $payload = ['trip_id' => $trip->trip_id, 'title' => 'Planning Call'];
    $res = $this->postJson('/api/calls', $payload);

    $res->assertStatus(201)->assertJsonStructure(['call_id', 'trip_id', 'title']);

    $callId = $res->json('call_id');

    $itemRes = $this->postJson("/api/calls/{$callId}/action-items", ['description' => 'Follow up']);
    $itemRes->assertStatus(201)->assertJsonStructure(['action_item_id', 'call_id', 'description']);

    $this->assertDatabaseHas('call_action_items', ['call_id' => $callId, 'description' => 'Follow up']);
});
