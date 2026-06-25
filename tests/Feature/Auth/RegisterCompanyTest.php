<?php

use App\Models\User;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can successfully register a company and an owner account simultaneously', function () {
    $payload = [
        'email' => 'ceo@bofinvestments.com',
        'company_name' => 'Bof Investments Ltd',
        'country' => 'Ghana',
        'business_type' => "Creative Direction Company",
        'username' => 'bof_ceo',
        'password' => 'SecurePassword2026!',
        'password_confirmation' => 'SecurePassword2026!',
    ];

    $response = $this->postJson('/api/v1/auth/register-company', $payload);
    // 1. Assert response metadata structure
    print_r($response->json());
    $response->assertStatus(200)
        ->assertJsonStructure([
            'access_token',
            'token_type',
            'user' => ['user_id',
                    'email',
                    'display_name',
                    'status',
                    'companies' => [ "*" => ['company_id', 'company_name', 'status']]
                    ],
        ]);

    // 2. Verify relational database atomic updates
    $this->assertDatabaseHas('users', [
        'email' => 'ceo@bofinvestments.com',
        'display_name' => 'bof_ceo',
    ]);

    $this->assertDatabaseHas('companies', [
        'company_name' => 'Bof Investments Ltd',
        'country' => 'Ghana',
    ]);

    // 3. Confirm the owner-to-workspace mapping pivot entry exists
    $user = User::where('email', 'ceo@bofinvestments.com')->first();
    $company = Company::where('company_name', 'Bof Investments Ltd')->first();

    $this->assertNotNull($user);
    $this->assertNotNull($company);
    
    $this->assertTrue($user->companies->contains($company));
});

test('registration fails if validation parameters are violated', function () {
    $response = $this->postJson('/api/v1/auth/register-company', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'company_name', 'country', 'business_type', 'username', 'password']);
});
