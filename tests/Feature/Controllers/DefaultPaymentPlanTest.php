<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Trip;
use App\Models\Itinerary;
use App\Models\ItineraryFlight;
use App\Models\PaymentPlan;
use App\Services\IdGeneratorService;

uses(RefreshDatabase::class);

function attachOwnerToTrip(Trip $trip, User $user): void
{
    $trip->company->users()->attach($user->user_id, [
        'role' => 'owner', 'is_default' => true, 'is_enabled' => true, 'joined_at' => now(),
    ]);
}

// ItineraryFlightFactory is broken (targets a stale 'itinerary_flight_id' column that doesn't
// exist on the model — the real primary key is 'flight_id'), so build the row directly instead
// of going through ItineraryFlight::factory().
function addFlightCost(Itinerary $itinerary, float $cost, string $currency): ItineraryFlight
{
    return ItineraryFlight::create([
        'flight_id' => IdGeneratorService::generateId('FLT'),
        'itinerary_id' => $itinerary->itinerary_id,
        'cost' => $cost,
        'currency' => $currency,
        'status' => 'pending',
    ]);
}

test('accepting an itinerary auto-creates a full plan and a 3-installment plan summing to the total', function () {
    $trip = Trip::factory()->create(['start_date' => now()->addDays(30)->toDateString()]);
    $itinerary = Itinerary::factory()->create(['trip_id' => $trip->trip_id]);
    addFlightCost($itinerary, 1000, 'USD');

    // Total = 1000 flight cost + 5% service fee = 1050.
    $res = $this->postJson("/api/public/trips/{$trip->trip_id}/itineraries/{$itinerary->itinerary_id}/accept");
    $res->assertStatus(200);

    $plans = PaymentPlan::where('trip_id', $trip->trip_id)->get()->keyBy(fn ($p) => $p->plan_type->value);

    expect($plans)->toHaveKey('full');
    expect($plans)->toHaveKey('installments');

    $full = $plans['full'];
    expect((float) $full->total_amount)->toBe(1050.0);
    expect($full->installments)->toHaveCount(1);
    expect((float) $full->installments->first()->amount)->toBe(1050.0);

    $installments = $plans['installments'];
    expect((float) $installments->total_amount)->toBe(1050.0);
    expect($installments->installments)->toHaveCount(3);
    $sum = round($installments->installments->sum(fn ($i) => (float) $i->amount), 2);
    expect($sum)->toBe(1050.0);

    // Reference codes are distinct so the traveler can pick either on the pay page.
    expect($full->payment_reference)->not->toBe($installments->payment_reference);
});

test('the 3-installment plan spreads due dates between today and the trip start date', function () {
    $trip = Trip::factory()->create(['start_date' => now()->addDays(30)->toDateString()]);
    $itinerary = Itinerary::factory()->create(['trip_id' => $trip->trip_id]);
    addFlightCost($itinerary, 900, 'GHS');

    $this->postJson("/api/public/trips/{$trip->trip_id}/itineraries/{$itinerary->itinerary_id}/accept")->assertStatus(200);

    $plan = PaymentPlan::where('trip_id', $trip->trip_id)->where('plan_type', 'installments')->first();
    $dueDates = $plan->installments->sortBy('sequence')->pluck('due_date')->map(fn ($d) => $d?->toDateString())->values();

    expect($dueDates[0])->toBe(now()->toDateString());
    expect($dueDates[2])->toBe($trip->start_date->toDateString());
    expect($dueDates[1])->not->toBeNull();
});

test('accepting an itinerary twice does not duplicate the default plans', function () {
    $trip = Trip::factory()->create();
    $itinerary = Itinerary::factory()->create(['trip_id' => $trip->trip_id]);
    addFlightCost($itinerary, 500, 'GHS');

    $this->postJson("/api/public/trips/{$trip->trip_id}/itineraries/{$itinerary->itinerary_id}/accept")->assertStatus(200);
    $this->postJson("/api/public/trips/{$trip->trip_id}/itineraries/{$itinerary->itinerary_id}/accept")->assertStatus(200);

    expect(PaymentPlan::where('trip_id', $trip->trip_id)->where('plan_type', 'full')->count())->toBe(1);
    expect(PaymentPlan::where('trip_id', $trip->trip_id)->where('plan_type', 'installments')->count())->toBe(1);
});

test('a trip with no cost yet gets no default plans on accept', function () {
    $trip = Trip::factory()->create();
    $itinerary = Itinerary::factory()->create(['trip_id' => $trip->trip_id]);

    $this->postJson("/api/public/trips/{$trip->trip_id}/itineraries/{$itinerary->itinerary_id}/accept")->assertStatus(200);

    expect(PaymentPlan::where('trip_id', $trip->trip_id)->count())->toBe(0);
});

test('a staffer confirming an itinerary from the dashboard also creates the default plans', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwnerToTrip($trip, $user);
    $itinerary = Itinerary::factory()->create(['trip_id' => $trip->trip_id]);
    addFlightCost($itinerary, 800, 'GHS');
    $this->actingAs($user, 'sanctum');

    $this->putJson("/api/itinerary/{$itinerary->itinerary_id}", ['status' => 'confirmed'])->assertStatus(200);

    expect(PaymentPlan::where('trip_id', $trip->trip_id)->count())->toBe(2);
});

test('a trip can still have a hand-built CUSTOM plan alongside the auto-created defaults', function () {
    $user = User::factory()->create();
    $trip = Trip::factory()->create();
    attachOwnerToTrip($trip, $user);
    $itinerary = Itinerary::factory()->create(['trip_id' => $trip->trip_id]);
    addFlightCost($itinerary, 1000, 'GHS');
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/public/trips/{$trip->trip_id}/itineraries/{$itinerary->itinerary_id}/accept")->assertStatus(200);

    $custom = $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 1050, 'currency' => 'GHS', 'installments' => [['amount' => 1050]],
    ]);
    $custom->assertStatus(201);

    expect(PaymentPlan::where('trip_id', $trip->trip_id)->count())->toBe(3);

    // A second CUSTOM plan is still rejected — only the CUSTOM slot is exclusive.
    $this->postJson("/api/trips/{$trip->trip_id}/payment-plan", [
        'total_amount' => 1050, 'currency' => 'GHS', 'installments' => [['amount' => 1050]],
    ])->assertStatus(422);
});

test('public trip view exposes payment_plans with both default reference codes', function () {
    $trip = Trip::factory()->create();
    $itinerary = Itinerary::factory()->create(['trip_id' => $trip->trip_id]);
    addFlightCost($itinerary, 600, 'GHS');
    $this->postJson("/api/public/trips/{$trip->trip_id}/itineraries/{$itinerary->itinerary_id}/accept")->assertStatus(200);

    $res = $this->getJson("/api/public/trips/{$trip->trip_id}");
    $res->assertStatus(200);

    $types = collect($res->json('payment_plans'))->pluck('plan_type');
    expect($types->sort()->values()->all())->toBe(['full', 'installments']);
});
