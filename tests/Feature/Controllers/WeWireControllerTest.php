<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Trip;
use App\Models\Company;
use App\Models\WeWireVirtualAccount;
use App\Models\WeWireBeneficiary;
use App\Models\WeWireDisbursement;

uses(RefreshDatabase::class);

function attachOwner(Trip $trip, User $user): void
{
    $trip->company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
}

// Signs a webhook body exactly the way WeWireService::verifyWebhookSignature expects, and
// posts it — shared by every test below that needs to simulate an inbound WeWire webhook.
function postSignedWebhook(string $eventType, array $data): \Illuminate\Testing\TestResponse
{
    config(['services.wewire.webhook_secret' => 'whsec_' . base64_encode('unit-test-secret')]);

    $body = json_encode(['eventType' => $eventType, 'data' => $data]);
    $id = 'msg_' . str()->random(8);
    $timestamp = (string) time();
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", 'unit-test-secret', true));

    return test()->call('POST', '/api/payments/wewire/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_webhook-id' => $id,
        'HTTP_webhook-timestamp' => $timestamp,
        'HTTP_webhook-signature' => "v1,{$signature}",
    ], $body);
}

test('payment plan store fails when installment amounts do not sum to the total', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 1000,
        'currency' => 'USD',
        'installments' => [['amount' => 400], ['amount' => 400]],
    ]);

    $res->assertStatus(422)->assertJsonFragment(['message' => 'Installment amounts must sum to the total amount.']);
});

test('payment plan store creates a plan with a unique reference code and ordered installments', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 1000,
        'currency' => 'USD',
        'installments' => [['amount' => 600], ['amount' => 400]],
    ]);

    $res->assertStatus(201);
    $res->assertJsonPath('installments.0.sequence', 1);
    $res->assertJsonPath('installments.1.sequence', 2);

    $reference = $res->json('payment_reference');
    expect($reference)->toMatch('/^[A-Z]{5}[0-9]{3}$/');

    $this->assertDatabaseHas('payment_plans', ['trip_id' => $trip->trip_id, 'payment_reference' => $reference]);
});

test('a trip can only have one payment plan', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $this->actingAs($user, 'sanctum');

    $payload = ['total_amount' => 500, 'currency' => 'GHS', 'installments' => [['amount' => 500]]];
    $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", $payload)->assertStatus(201);

    $res = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", $payload);
    $res->assertStatus(422)->assertJsonFragment(['message' => 'This trip already has a payment plan.']);
});

test('virtual account request is capped at 3 accounts and one per currency', function () {
    Http::fake(function ($request) {
        return Http::response(['id' => 'wewire-va-' . str()->random(6), 'status' => 'REQUESTED'], 202);
    });

    $user = User::factory()->create();
    $company = Company::factory()->create(['wewire_subcustomer_id' => 'wewire-sub-123']);
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $this->postJson('/api/wewire/accounts', ['currency' => 'USD'])->assertStatus(201);
    $this->postJson('/api/wewire/accounts', ['currency' => 'EUR'])->assertStatus(201);
    $this->postJson('/api/wewire/accounts', ['currency' => 'GBP'])->assertStatus(201);

    // A 4th currency should be rejected — max 3 accounts per company.
    $res = $this->postJson('/api/wewire/accounts', ['currency' => 'GHS']);
    $res->assertStatus(422)->assertJsonFragment(['message' => 'You can only have up to 3 currency accounts.']);

    $this->assertDatabaseCount('wewire_virtual_accounts', 3);
});

test('requesting a currency the company already has an account for is rejected', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-va-1', 'status' => 'REQUESTED'], 202));

    $user = User::factory()->create();
    $company = Company::factory()->create(['wewire_subcustomer_id' => 'wewire-sub-123']);
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $this->postJson('/api/wewire/accounts', ['currency' => 'USD'])->assertStatus(201);
    $res = $this->postJson('/api/wewire/accounts', ['currency' => 'USD']);

    $res->assertStatus(422)->assertJsonFragment(['message' => 'You already have a USD account.']);
});

test('public lookup resolves a payment plan by its reference code', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 200, 'currency' => 'USD', 'installments' => [['amount' => 200]],
    ]);
    $reference = $res->json('payment_reference');

    $lookup = $this->getJson("/api/public/payments/wewire/lookup/{$reference}");
    $lookup->assertStatus(200)
        ->assertJsonPath('payment_reference', $reference)
        ->assertJsonPath('outstanding', 200)
        ->assertJsonPath('payment_account', null); // no ACTIVE virtual account provisioned yet
});

test('the public pay page verifies the account live with WeWire before letting the customer proceed', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-acc-live-1', 'status' => 'ACTIVE'], 200));

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $company->update(['wewire_subcustomer_id' => 'wewire-sub-verify-1']);
    $this->actingAs($user, 'sanctum');

    WeWireVirtualAccount::create([
        'id' => 'VAC_VERIFY001', 'company_id' => $company->company_id, 'currency' => 'GHS',
        'status' => 'active', 'wewire_account_id' => 'wewire-acc-live-1',
    ]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 400, 'currency' => 'GHS', 'installments' => [['amount' => 400]],
    ]);
    $reference = $planRes->json('payment_reference');

    $res = $this->postJson("/api/public/payments/wewire/simulate/{$reference}");

    $res->assertStatus(200)->assertJsonPath('verified', true)->assertJsonPath('outstanding', 400);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/accounts/wewire-acc-live-1'));
    $this->assertDatabaseMissing('wewire_inbound_transactions', ['matched_payment_reference' => $reference]);
});

test('the public pay page offers the simulated fallback when the account fails live verification', function () {
    Http::fake(fn () => Http::response(['status' => 'REJECTED', 'message' => 'Business KYC is still under review'], 200));

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $company->update(['wewire_subcustomer_id' => 'wewire-sub-verify-2']);
    $this->actingAs($user, 'sanctum');

    WeWireVirtualAccount::create([
        'id' => 'VAC_VERIFY002', 'company_id' => $company->company_id, 'currency' => 'GHS',
        'status' => 'active', 'wewire_account_id' => 'wewire-acc-blocked-1', 'is_simulated' => true,
    ]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 250, 'currency' => 'GHS', 'installments' => [['amount' => 250]],
    ]);
    $reference = $planRes->json('payment_reference');

    $res = $this->postJson("/api/public/payments/wewire/simulate/{$reference}");
    $res->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('title', 'Response from wewire server');
    $this->assertDatabaseMissing('wewire_inbound_transactions', ['matched_payment_reference' => $reference]);

    config(['services.wewire.simulate' => true]);
    $confirmed = $this->postJson("/api/public/payments/wewire/simulate/{$reference}", ['confirm_simulated' => true]);
    $confirmed->assertStatus(200)->assertJsonPath('outstanding', 0);
    $this->assertDatabaseHas('wewire_inbound_transactions', ['matched_payment_reference' => $reference, 'is_simulated' => true]);
});

test('confirming a simulated public payment is refused when WeWire simulation mode is off', function () {
    Http::fake(fn () => Http::response(['status' => 'REJECTED'], 200));
    config(['services.wewire.simulate' => false]);

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $this->actingAs($user, 'sanctum');

    WeWireVirtualAccount::create([
        'id' => 'VAC_VERIFY003', 'company_id' => $company->company_id, 'currency' => 'GHS', 'status' => 'active',
    ]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 100, 'currency' => 'GHS', 'installments' => [['amount' => 100]],
    ]);
    $reference = $planRes->json('payment_reference');

    $res = $this->postJson("/api/public/payments/wewire/simulate/{$reference}", ['confirm_simulated' => true]);
    $res->assertStatus(403);
});

test('webhook rejects a request with an invalid signature', function () {
    config(['services.wewire.webhook_secret' => 'whsec_' . base64_encode('unit-test-secret')]);

    $res = $this->postJson('/api/payments/wewire/webhook', ['eventType' => 'transaction.pay_in', 'data' => []], [
        'webhook-id' => 'msg_1',
        'webhook-timestamp' => (string) time(),
        'webhook-signature' => 'v1,bogus',
    ]);

    $res->assertStatus(401);
});

test('a webhook pay_in with a matching reference auto-completes the installment', function () {
    config(['services.wewire.webhook_secret' => 'whsec_' . base64_encode('unit-test-secret')]);

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $this->actingAs($user, 'sanctum');

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 300, 'currency' => 'USD', 'installments' => [['amount' => 300]],
    ]);
    $reference = $planRes->json('payment_reference');

    $body = json_encode([
        'eventType' => 'transaction.pay_in',
        'data' => ['id' => 'wewire-tx-abc', 'amount' => 300, 'currency' => 'USD', 'reference' => "Transfer ref {$reference}"],
    ]);

    $id = 'msg_2';
    $timestamp = (string) time();
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", 'unit-test-secret', true));

    $res = $this->call('POST', '/api/payments/wewire/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_webhook-id' => $id,
        'HTTP_webhook-timestamp' => $timestamp,
        'HTTP_webhook-signature' => "v1,{$signature}",
    ], $body);

    $res->assertStatus(200);

    $this->assertDatabaseHas('wewire_inbound_transactions', ['wewire_transaction_id' => 'wewire-tx-abc', 'status' => 'reconciled']);
    $this->assertDatabaseHas('installments', ['payment_plan_id' => $planRes->json('id'), 'status' => 'paid']);
    $this->assertDatabaseHas('payment_plans', ['id' => $planRes->json('id'), 'status' => 'completed']);
});

test('a pay_in on a disburse-configured account creates a tracked disbursement', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-payout-1', 'status' => 'PENDING'], 201));

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;

    $account = WeWireVirtualAccount::create([
        'id' => 'VAC_TEST0001', 'company_id' => $company->company_id, 'currency' => 'USD',
        'wewire_account_id' => 'wewire-va-1', 'status' => 'active', 'fund_handling' => 'disburse',
    ]);
    $beneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_TEST0001', 'company_id' => $company->company_id, 'wewire_beneficiary_id' => 'wewire-ben-1',
        'currency' => 'USD', 'account_name' => 'Test Beneficiary', 'settlement_method' => 'WIRE',
    ]);
    $account->update(['beneficiary_account_id' => $beneficiary->id]);

    $this->actingAs($user, 'sanctum');
    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 250, 'currency' => 'USD', 'installments' => [['amount' => 250]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-disburse-1', 'amount' => 250, 'currency' => 'USD',
        'accountId' => 'wewire-va-1', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    $this->assertDatabaseHas('wewire_disbursements', [
        'virtual_account_id' => $account->id,
        'beneficiary_id' => $beneficiary->id,
        'wewire_transaction_id' => 'wewire-payout-1',
        'status' => 'pending',
        'amount' => 250,
    ]);
});

test('transaction.status_updated marks a matching disbursement successful', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $account = WeWireVirtualAccount::create([
        'id' => 'VAC_TEST0002', 'company_id' => $company->company_id, 'currency' => 'USD', 'status' => 'active',
    ]);
    $beneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_TEST0002', 'company_id' => $company->company_id, 'currency' => 'USD',
        'account_name' => 'Test Beneficiary', 'settlement_method' => 'WIRE',
    ]);
    $disbursement = WeWireDisbursement::create([
        'id' => 'WWD_TEST0001', 'virtual_account_id' => $account->id, 'beneficiary_id' => $beneficiary->id,
        'wewire_transaction_id' => 'wewire-payout-2', 'amount' => 100, 'currency' => 'USD',
        'status' => 'pending', 'initiated_at' => now(),
    ]);

    postSignedWebhook('transaction.status_updated', [
        'id' => 'wewire-payout-2', 'status' => 'SUCCESSFUL', 'fee' => 1.5,
    ])->assertStatus(200);

    $disbursement->refresh();
    expect($disbursement->status->value)->toBe('successful');
    expect((float) $disbursement->fee)->toBe(1.5);
    expect($disbursement->settled_at)->not->toBeNull();
});

test('transaction.status_updated reversing a collection payment unwinds the installment', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $this->actingAs($user, 'sanctum');

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 300, 'currency' => 'USD', 'installments' => [['amount' => 300]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-reverse-me', 'amount' => 300, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    $this->assertDatabaseHas('payment_plans', ['id' => $planRes->json('id'), 'status' => 'completed']);
    $this->assertDatabaseHas('installments', ['payment_plan_id' => $planRes->json('id'), 'status' => 'paid']);

    postSignedWebhook('transaction.status_updated', [
        'id' => 'wewire-tx-reverse-me', 'status' => 'REVERSED',
    ])->assertStatus(200);

    $this->assertDatabaseHas('payment_plans', ['id' => $planRes->json('id'), 'status' => 'active']);
    $this->assertDatabaseHas('installments', ['payment_plan_id' => $planRes->json('id'), 'status' => 'pending']);

    $inbound = \App\Models\WeWireInboundTransaction::where('wewire_transaction_id', 'wewire-tx-reverse-me')->first();
    $this->assertDatabaseHas('transactions', ['transaction_id' => $inbound->transaction_id, 'status' => 'refunded']);
});

test('retrying a failed disbursement creates a new tracked attempt', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-payout-retry-1', 'status' => 'PENDING'], 201));

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $account = WeWireVirtualAccount::create([
        'id' => 'VAC_TEST0003', 'company_id' => $company->company_id, 'currency' => 'USD',
        'status' => 'active', 'fund_handling' => 'disburse',
    ]);
    $beneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_TEST0003', 'company_id' => $company->company_id, 'currency' => 'USD',
        'account_name' => 'Test Beneficiary', 'settlement_method' => 'WIRE',
    ]);
    $failed = WeWireDisbursement::create([
        'id' => 'WWD_TEST0002', 'virtual_account_id' => $account->id, 'beneficiary_id' => $beneficiary->id,
        'amount' => 75, 'currency' => 'USD', 'status' => 'initiation_failed',
        'failure_reason' => 'timeout', 'initiated_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum');
    $res = $this->postJson("/api/wewire/disbursements/{$failed->id}/retry");

    $res->assertStatus(201);
    $this->assertDatabaseCount('wewire_disbursements', 2);
    $this->assertDatabaseHas('wewire_disbursements', ['id' => $failed->id, 'status' => 'initiation_failed']);
    $this->assertDatabaseHas('wewire_disbursements', ['wewire_transaction_id' => 'wewire-payout-retry-1', 'status' => 'pending']);
});

test('trip balances shows a held amount after a completed WeWire payment', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $this->actingAs($user, 'sanctum');

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 400, 'currency' => 'USD', 'installments' => [['amount' => 400]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-balance-1', 'amount' => 400, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    $res = $this->getJson('/api/wewire/trip-balances');
    $res->assertStatus(200)->assertJsonFragment([
        'trip_id' => $trip->trip_id,
        'held_balance' => 400,
        'can_payout' => false, // no beneficiary/active account configured yet
    ]);
});

test('paying out a trip creates a disbursement for the held balance and clears it', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-payout-trip-1', 'status' => 'PENDING'], 201));

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $this->actingAs($user, 'sanctum');

    $account = WeWireVirtualAccount::create([
        'id' => 'VAC_TRIP0001', 'company_id' => $company->company_id, 'currency' => 'USD', 'status' => 'active',
    ]);
    $beneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_TRIP0001', 'company_id' => $company->company_id, 'currency' => 'USD',
        'account_name' => 'Agency Payout', 'settlement_method' => 'WIRE',
    ]);
    $account->update(['beneficiary_account_id' => $beneficiary->id]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 500, 'currency' => 'USD', 'installments' => [['amount' => 500]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-balance-2', 'amount' => 500, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    // Now it should be payable.
    $this->getJson('/api/wewire/trip-balances')->assertJsonFragment(['trip_id' => $trip->trip_id, 'can_payout' => true, 'beneficiary_id' => $beneficiary->id]);

    $payout = $this->postJson("/api/trips/{$trip->trip_id}/payout", ['beneficiary_id' => $beneficiary->id]);
    $payout->assertStatus(201)->assertJsonPath('amount', 500);

    $this->assertDatabaseHas('wewire_disbursements', [
        'source_trip_id' => $trip->trip_id,
        'wewire_transaction_id' => 'wewire-payout-trip-1',
        'amount' => 500,
    ]);

    // Held balance should now be zero — the trip drops off the balances list entirely.
    $again = $this->getJson('/api/wewire/trip-balances');
    $again->assertStatus(200)->assertJsonMissing(['trip_id' => $trip->trip_id]);
});

test('paying out a trip twice in a row is rejected the second time', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-payout-trip-2', 'status' => 'PENDING'], 201));

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $this->actingAs($user, 'sanctum');

    $account = WeWireVirtualAccount::create([
        'id' => 'VAC_TRIP0002', 'company_id' => $company->company_id, 'currency' => 'USD', 'status' => 'active',
    ]);
    $beneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_TRIP0002', 'company_id' => $company->company_id, 'currency' => 'USD',
        'account_name' => 'Agency Payout', 'settlement_method' => 'WIRE',
    ]);
    $account->update(['beneficiary_account_id' => $beneficiary->id]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 150, 'currency' => 'USD', 'installments' => [['amount' => 150]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-balance-3', 'amount' => 150, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    $this->postJson("/api/trips/{$trip->trip_id}/payout", ['beneficiary_id' => $beneficiary->id])->assertStatus(201);

    $second = $this->postJson("/api/trips/{$trip->trip_id}/payout", ['beneficiary_id' => $beneficiary->id]);
    $second->assertStatus(422)->assertJsonFragment(['message' => 'Nothing is currently held for this trip.']);
});

test('paying out a trip without a beneficiary_id is rejected as a validation error', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $this->actingAs($user, 'sanctum');

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 100, 'currency' => 'USD', 'installments' => [['amount' => 100]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-balance-4', 'amount' => 100, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    $res = $this->postJson("/api/trips/{$trip->trip_id}/payout");
    $res->assertStatus(422)->assertJsonValidationErrors(['beneficiary_id']);
});

test('paying out a trip to a mismatched-currency beneficiary is rejected', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $this->actingAs($user, 'sanctum');

    $eurBeneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_TRIP0005', 'company_id' => $company->company_id, 'currency' => 'EUR',
        'account_name' => 'Wrong Currency', 'settlement_method' => 'SEPA',
    ]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 100, 'currency' => 'USD', 'installments' => [['amount' => 100]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-balance-5', 'amount' => 100, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    $res = $this->postJson("/api/trips/{$trip->trip_id}/payout", ['beneficiary_id' => $eurBeneficiary->id]);
    $res->assertStatus(422)->assertJsonFragment(['message' => "That beneficiary is in EUR, but this trip's held balance is in USD."]);
});

test('paying out a trip to a provider for a specific line item with a chosen amount', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-payout-provider-1', 'status' => 'PENDING'], 201));

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $this->actingAs($user, 'sanctum');

    WeWireVirtualAccount::create([
        'id' => 'VAC_TRIP0006', 'company_id' => $company->company_id, 'currency' => 'USD', 'status' => 'active',
    ]);
    $provider = WeWireBeneficiary::create([
        'id' => 'WBN_TRIP0006', 'company_id' => $company->company_id, 'currency' => 'USD',
        'beneficiary_type' => 'provider', 'label' => 'Emirates Airlines',
        'account_name' => 'Emirates Airlines Ltd', 'settlement_method' => 'WIRE',
    ]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 1000, 'currency' => 'USD', 'installments' => [['amount' => 1000]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-balance-6', 'amount' => 1000, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    // Pay the airline less than the full held balance — a chosen partial amount.
    $res = $this->postJson("/api/trips/{$trip->trip_id}/payout", [
        'beneficiary_id' => $provider->id,
        'amount' => 400,
        'line_item_type' => 'flight',
        'line_item_id' => 'FLT_ABC123',
        'line_item_label' => 'Emirates EK783',
    ]);

    $res->assertStatus(201)->assertJsonPath('amount', 400);
    $this->assertDatabaseHas('wewire_disbursements', [
        'source_trip_id' => $trip->trip_id,
        'beneficiary_id' => $provider->id,
        'line_item_type' => 'flight',
        'line_item_id' => 'FLT_ABC123',
        'line_item_label' => 'Emirates EK783',
        'amount' => 400,
    ]);

    // 600 of the original 1000 should still be held (available for other providers/the agency).
    $balances = $this->getJson('/api/wewire/trip-balances');
    $balances->assertJsonFragment(['trip_id' => $trip->trip_id, 'held_balance' => 600]);
});

test('paying out a trip for an amount exceeding the held balance is rejected', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $this->actingAs($user, 'sanctum');

    $beneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_TRIP0007', 'company_id' => $company->company_id, 'currency' => 'USD',
        'account_name' => 'Agency Payout', 'settlement_method' => 'WIRE',
    ]);
    WeWireVirtualAccount::create([
        'id' => 'VAC_TRIP0007', 'company_id' => $company->company_id, 'currency' => 'USD', 'status' => 'active',
    ]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 100, 'currency' => 'USD', 'installments' => [['amount' => 100]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-balance-7', 'amount' => 100, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    $res = $this->postJson("/api/trips/{$trip->trip_id}/payout", ['beneficiary_id' => $beneficiary->id, 'amount' => 150]);
    $res->assertStatus(422)->assertJsonFragment(['message' => 'Only 100 USD is currently held for this trip.']);
});

test('retrying a successful disbursement is rejected', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $account = WeWireVirtualAccount::create([
        'id' => 'VAC_TEST0004', 'company_id' => $company->company_id, 'currency' => 'USD', 'status' => 'active',
    ]);
    $beneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_TEST0004', 'company_id' => $company->company_id, 'currency' => 'USD',
        'account_name' => 'Test Beneficiary', 'settlement_method' => 'WIRE',
    ]);
    $successful = WeWireDisbursement::create([
        'id' => 'WWD_TEST0003', 'virtual_account_id' => $account->id, 'beneficiary_id' => $beneficiary->id,
        'wewire_transaction_id' => 'wewire-payout-done', 'amount' => 50, 'currency' => 'USD',
        'status' => 'successful', 'initiated_at' => now(), 'settled_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum');
    $res = $this->postJson("/api/wewire/disbursements/{$successful->id}/retry");

    $res->assertStatus(422)->assertJsonFragment(['message' => 'Only a failed, reversed, or cancelled disbursement can be retried.']);
});

test('beneficiary creation is simulated and never hits the real WeWire API', function () {
    Http::fake(); // no stubbed response registered — if anything is actually requested, ->assertNothingSent() below fails.
    config(['services.wewire.simulate' => true]);

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson('/api/wewire/beneficiaries', [
        'beneficiary_type' => 'provider',
        'label' => 'Emirates Airlines',
        'currency' => 'USD',
        'account_name' => 'Emirates Airlines Ltd',
        'country' => 'ARE',
        'settlement_method' => 'WIRE',
        'routing_number' => '021000021',
        'account_category' => 'CHECKING',
        'address_line1' => '1 Airport Road',
        'city' => 'Dubai',
    ]);

    $res->assertStatus(201)
        ->assertJsonPath('beneficiary_type', 'provider')
        ->assertJsonPath('label', 'Emirates Airlines');
    expect($res->json('wewire_beneficiary_id'))->toStartWith('SIM_BEN_');
    Http::assertNothingSent();
});

test('beneficiaries can be filtered by type', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    WeWireBeneficiary::create([
        'id' => 'WBN_FILT0001', 'company_id' => $company->company_id, 'currency' => 'USD',
        'beneficiary_type' => 'agency', 'account_name' => 'Agency Account', 'settlement_method' => 'WIRE',
    ]);
    WeWireBeneficiary::create([
        'id' => 'WBN_FILT0002', 'company_id' => $company->company_id, 'currency' => 'USD',
        'beneficiary_type' => 'provider', 'label' => 'Hilton Accra', 'account_name' => 'Hilton Ghana Ltd', 'settlement_method' => 'WIRE',
    ]);

    $this->actingAs($user, 'sanctum');

    $providers = $this->getJson('/api/wewire/beneficiaries?type=provider');
    $providers->assertStatus(200)->assertJsonCount(1)->assertJsonFragment(['label' => 'Hilton Accra']);

    $all = $this->getJson('/api/wewire/beneficiaries');
    $all->assertStatus(200)->assertJsonCount(2);
});

test('KYC submission is simulated and never hits the real WeWire API', function () {
    Http::fake();
    config(['services.wewire.simulate' => true]);

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $sub = $this->postJson('/api/wewire/subcustomer', [
        'email' => 'ops@example.com', 'country' => 'GHA', 'business_type' => 'GENERAL_BUSINESS',
    ]);
    $sub->assertStatus(200);
    expect($sub->json('wewire_subcustomer_id'))->toStartWith('SIM_SUB_');
    Http::assertNothingSent();
});

test('virtual account requests are NOT simulated and still call the real API', function () {
    Http::fake(fn () => Http::response(['id' => 'wewire-va-real-1', 'status' => 'REQUESTED'], 202));
    config(['services.wewire.simulate' => true]); // simulate=true should not affect this endpoint

    $user = User::factory()->create();
    $company = Company::factory()->create(['wewire_subcustomer_id' => 'wewire-sub-real-123']);
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson('/api/wewire/accounts', ['currency' => 'USD']);

    $res->assertStatus(201)->assertJsonPath('wewire_account_id', 'wewire-va-real-1');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/accounts/request'));
});

test('a virtual account request WeWire accepts but blocks (e.g. business still in review) also offers the simulated fallback', function () {
    // 200 OK, but no account `id` — WeWire accepted the HTTP call yet couldn't actually issue
    // an account (blocked pending business/KYC review). Not an HTTP failure, so this only
    // reaches the popup because of WeWireService::liveCall's $isUsable check.
    Http::fake(fn () => Http::response(['status' => 'REJECTED', 'message' => 'Business KYC is still under review'], 200));

    $user = User::factory()->create();
    $company = Company::factory()->create(['wewire_subcustomer_id' => 'wewire-sub-in-review-1']);
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson('/api/wewire/accounts', ['currency' => 'USD']);

    $res->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('title', 'Response from wewire server')
        ->assertJsonPath('error.status', 200)
        ->assertJsonPath('error.body.message', 'Business KYC is still under review');
    $this->assertDatabaseMissing('wewire_virtual_accounts', ['company_id' => $company->company_id]);

    $confirmed = $this->postJson('/api/wewire/accounts', ['currency' => 'USD', 'confirm_simulated' => true]);
    $confirmed->assertStatus(201)->assertJsonPath('is_simulated', true);
});

test('a failed virtual account request offers a simulated fallback instead of erroring out', function () {
    Http::fake(fn () => Http::response(['message' => 'Service unavailable'], 503));

    $user = User::factory()->create();
    $company = Company::factory()->create(['wewire_subcustomer_id' => 'wewire-sub-fallback-1']);
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $res = $this->postJson('/api/wewire/accounts', ['currency' => 'USD']);

    $res->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('title', 'Response from wewire server')
        ->assertJsonPath('error.status', 503);
    $this->assertNotNull($res->json('simulated.id'));
    $this->assertDatabaseMissing('wewire_virtual_accounts', ['company_id' => $company->company_id]);

    // The frontend resubmits with confirm_simulated after the user accepts the popup.
    $confirmed = $this->postJson('/api/wewire/accounts', ['currency' => 'USD', 'confirm_simulated' => true]);
    $confirmed->assertStatus(201)->assertJsonPath('is_simulated', true);
    $this->assertDatabaseHas('wewire_virtual_accounts', ['company_id' => $company->company_id, 'is_simulated' => true]);
});

test('a failed real beneficiary creation offers a simulated fallback instead of erroring out', function () {
    // WEWIRE_SIMULATE is off (as it would be once real KYC/beneficiary creation is unblocked),
    // so createBeneficiary is genuinely live — and WeWire's sandbox 503ing (a real failure mode
    // hit during development) should offer the same fallback popup as everything else.
    config(['services.wewire.simulate' => false]);
    Http::fake(fn () => Http::response('<html>503 Service Temporarily Unavailable</html>', 503));

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
    $this->actingAs($user, 'sanctum');

    $payload = [
        'beneficiary_type' => 'provider',
        'label' => 'Emirates Airlines',
        'currency' => 'USD',
        'account_name' => 'Emirates Airlines Ltd',
        'country' => 'ARE',
        'settlement_method' => 'WIRE',
        'routing_number' => '021000021',
        'account_category' => 'CHECKING',
        'address_line1' => '1 Airport Road',
        'city' => 'Dubai',
    ];

    $res = $this->postJson('/api/wewire/beneficiaries', $payload);
    $res->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('title', 'Response from wewire server');
    $this->assertDatabaseMissing('wewire_beneficiaries', ['company_id' => $company->company_id]);

    $confirmed = $this->postJson('/api/wewire/beneficiaries', array_merge($payload, ['confirm_simulated' => true]));
    $confirmed->assertStatus(201)->assertJsonPath('is_simulated', true);
    $this->assertDatabaseHas('wewire_beneficiaries', ['company_id' => $company->company_id, 'is_simulated' => true]);
});

test('a failed trip payout offers a simulated fallback that the dashboard can confirm', function () {
    Http::fake(fn () => Http::response(['message' => 'Gateway timeout'], 504));

    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwner($trip, $user);
    $company = $trip->company;
    $this->actingAs($user, 'sanctum');

    $account = WeWireVirtualAccount::create([
        'id' => 'VAC_FALLBK01', 'company_id' => $company->company_id, 'currency' => 'USD', 'status' => 'active',
    ]);
    $beneficiary = WeWireBeneficiary::create([
        'id' => 'WBN_FALLBK01', 'company_id' => $company->company_id, 'currency' => 'USD',
        'account_name' => 'Agency Payout', 'settlement_method' => 'WIRE',
    ]);
    $account->update(['beneficiary_account_id' => $beneficiary->id]);

    $planRes = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 300, 'currency' => 'USD', 'installments' => [['amount' => 300]],
    ]);
    $reference = $planRes->json('payment_reference');

    postSignedWebhook('transaction.pay_in', [
        'id' => 'wewire-tx-fallback-1', 'amount' => 300, 'currency' => 'USD', 'reference' => "Ref {$reference}",
    ])->assertStatus(200);

    $payout = $this->postJson("/api/trips/{$trip->trip_id}/payout", ['beneficiary_id' => $beneficiary->id]);
    $payout->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('title', 'Response from wewire server')
        ->assertJsonPath('error.status', 504);
    $this->assertDatabaseMissing('wewire_disbursements', ['source_trip_id' => $trip->trip_id]);

    $confirmed = $this->postJson("/api/trips/{$trip->trip_id}/payout", [
        'beneficiary_id' => $beneficiary->id, 'confirm_simulated' => true,
    ]);
    $confirmed->assertStatus(201)->assertJsonPath('is_simulated', true)->assertJsonPath('amount', 300);
    $this->assertDatabaseHas('wewire_disbursements', ['source_trip_id' => $trip->trip_id, 'is_simulated' => true]);
});
