<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can successfully authenticate with valid credentials', function () {
    $user = User::factory()->create([
        'password' => Hash::make('SecretPassword2026!'),
    ]);

    $payload = [
        'email' => $user->email,
        'password' => 'SecretPassword2026!',
    ];


    $response = $this->postJson('/api/auth/login', $payload);
    $response->assertStatus(200)
        ->assertJsonStructure([
            'access_token',
            'token_type',
            'user' => [
                'user_id',
                'email',
                'display_name'
            ]
        ]);
        
    $this->assertNotEmpty($response->json('access_token'));
});

test('authentication fails and returns a 401 when using an invalid password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('SecretPassword2026!'),
    ]);

    $payload = [
        'email' => $user->email,
        'password' => 'WrongPasswordAttempt',
    ];

    $response = $this->postJson('/api/auth/login', $payload);

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Invalid credentials.'
        ]);
});

test('login requires both email and password validation strings', function () {
    $response = $this->postJson('/api/auth/login', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});