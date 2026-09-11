<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Trip;
use App\Models\Company;
use App\Models\WeWireCryptoWallet;
use App\Models\PaymentPlan;

uses(RefreshDatabase::class);

function attachOwnerToCompany(Company $company, User $user): void
{
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
}

test('requesting a crypto wallet creates it from a successful live response', function () {
    Http::fake(fn () => Http::response([
        'id' => 'wewire-wallet-1', 'asset' => 'USDC', 'chain' => 'BASE', 'status' => 'ACTIVE', 'address' => '0xabc123',
    ], 201));

    $user = User::factory()->create();
    $company = Company::factory()->create(['wewire_subcustomer_id' => 'wewire-sub-wallet-1']);
    attachOwnerToCompany($company, $user);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson('/api/wewire/wallets', ['asset' => 'USDC', 'chain' => 'BASE']);

    $res->assertStatus(201)
        ->assertJsonPath('asset', 'USDC')
        ->assertJsonPath('chain', 'BASE')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('deposit_address', '0xabc123')
        ->assertJsonPath('is_simulated', false);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/wallets/request'));
});

test('requesting the same asset/chain wallet twice is rejected', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-wallet-x', 'status' => 'ACTIVE'], 201));

    $user = User::factory()->create();
    $company = Company::factory()->create(['wewire_subcustomer_id' => 'wewire-sub-wallet-2']);
    attachOwnerToCompany($company, $user);
    $this->actingAs($user, 'sanctum');

    $this->postJson('/api/wewire/wallets', ['asset' => 'USDC', 'chain' => 'BASE'])->assertStatus(201);
    $res = $this->postJson('/api/wewire/wallets', ['asset' => 'USDC', 'chain' => 'BASE']);

    $res->assertStatus(422)->assertJsonFragment(['message' => 'You already have a USDC wallet on BASE.']);
});

test('a failed wallet request offers the simulated fallback popup', function () {
    Http::fake(fn () => Http::response(['message' => 'Service unavailable'], 503));

    $user = User::factory()->create();
    $company = Company::factory()->create(['wewire_subcustomer_id' => 'wewire-sub-wallet-3']);
    attachOwnerToCompany($company, $user);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson('/api/wewire/wallets', ['asset' => 'USDT', 'chain' => 'TRON']);
    $res->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('title', 'Response from wewire server');
    $this->assertDatabaseMissing('wewire_crypto_wallets', ['company_id' => $company->company_id]);

    $confirmed = $this->postJson('/api/wewire/wallets', ['asset' => 'USDT', 'chain' => 'TRON', 'confirm_simulated' => true]);
    $confirmed->assertStatus(201)->assertJsonPath('is_simulated', true);
    $this->assertDatabaseHas('wewire_crypto_wallets', ['company_id' => $company->company_id, 'is_simulated' => true]);
});

test('the subcustomer.wallet.created webhook activates a pending wallet', function () {
    config(['services.wewire.webhook_secret' => 'whsec_' . base64_encode('unit-test-secret')]);

    $company = Company::factory()->create();
    $wallet = WeWireCryptoWallet::create([
        'id' => 'WCW_TEST0001', 'company_id' => $company->company_id, 'asset' => 'USDC', 'chain' => 'BASE',
        'wewire_wallet_id' => 'wewire-wallet-created-1', 'status' => 'requested',
    ]);

    $body = json_encode(['eventType' => 'subcustomer.wallet.created', 'data' => [
        'walletId' => 'wewire-wallet-created-1', 'asset' => 'USDC', 'chain' => 'BASE', 'address' => '0xdeadbeef',
    ]]);
    $id = 'msg_' . str()->random(8);
    $timestamp = (string) time();
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", 'unit-test-secret', true));

    $res = $this->call('POST', '/api/payments/wewire/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_webhook-id' => $id,
        'HTTP_webhook-timestamp' => $timestamp,
        'HTTP_webhook-signature' => "v1,{$signature}",
    ], $body);

    $res->assertStatus(200);
    expect($wallet->fresh()->status->value)->toBe('active');
    expect($wallet->fresh()->deposit_address)->toBe('0xdeadbeef');
});

test('the subcustomer.wallet.deposit.received webhook lands in the unmatched reconciliation queue', function () {
    config(['services.wewire.webhook_secret' => 'whsec_' . base64_encode('unit-test-secret')]);

    $company = Company::factory()->create();
    $wallet = WeWireCryptoWallet::create([
        'id' => 'WCW_TEST0002', 'company_id' => $company->company_id, 'asset' => 'USDC', 'chain' => 'BASE',
        'wewire_wallet_id' => 'wewire-wallet-deposit-1', 'status' => 'active', 'deposit_address' => '0xabc',
    ]);

    $body = json_encode(['eventType' => 'subcustomer.wallet.deposit.received', 'data' => [
        'id' => 'wewire-deposit-tx-1', 'walletId' => 'wewire-wallet-deposit-1', 'amount' => 250, 'txHash' => '0xhash123',
    ]]);
    $id = 'msg_' . str()->random(8);
    $timestamp = (string) time();
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", 'unit-test-secret', true));

    $res = $this->call('POST', '/api/payments/wewire/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_webhook-id' => $id,
        'HTTP_webhook-timestamp' => $timestamp,
        'HTTP_webhook-signature' => "v1,{$signature}",
    ], $body);

    $res->assertStatus(200);
    $this->assertDatabaseHas('wewire_inbound_transactions', [
        'wewire_transaction_id' => 'wewire-deposit-tx-1',
        'crypto_wallet_id' => $wallet->id,
        'tx_hash' => '0xhash123',
        'amount' => 250,
        'currency' => 'USD',
        'status' => 'unmatched',
    ]);
});

test('public lookup includes active crypto wallets regardless of the plan\'s own currency', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    $company = $trip->company;
    attachOwnerToCompany($company, $user);
    $this->actingAs($user, 'sanctum');

    WeWireCryptoWallet::create([
        'id' => 'WCW_TEST0003', 'company_id' => $company->company_id, 'asset' => 'USDC', 'chain' => 'BASE',
        'status' => 'active', 'deposit_address' => '0xusdcbase',
    ]);

    // Crypto is offered as a choice on every pay page now, not just for USD-denominated plans
    // (the traveler picks USD/GHS/crypto themselves — see WeWirePaymentController::
    // buildLookupResponse) — a GHS plan should show it too.
    $ghsPlan = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 500, 'currency' => 'GHS', 'installments' => [['amount' => 500]],
    ])->json('payment_reference');

    $lookup = $this->getJson("/api/public/payments/wewire/lookup/{$ghsPlan}");
    $lookup->assertStatus(200)->assertJsonPath('payment_wallets.0.address', '0xusdcbase');
});
