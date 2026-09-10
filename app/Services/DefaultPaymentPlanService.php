<?php

namespace App\Services;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Enums\PaymentPlanType;
use App\Models\Installment;
use App\Models\PaymentPlan;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Auto-creates a trip's two default WeWire payment plans — FULL (one lump-sum installment) and
 * INSTALLMENTS (three equal-ish installments) — once its cost is known, so the traveler always
 * has a reference code to pay through without an agency staffer manually setting one up first
 * (see PaymentPlanController::store for that manual/CUSTOM path, which still exists
 * separately). Called from TripController::acceptItinerary the moment an itinerary option is
 * confirmed, since that's the first point the trip's total cost is settled.
 */
class DefaultPaymentPlanService
{
    private const INSTALLMENT_COUNT = 3;

    // Idempotent: only fills in whichever of FULL/INSTALLMENTS doesn't already exist for this
    // trip (unique(trip_id, plan_type) also backstops this against a race). No-ops entirely if
    // there's nothing to collect. $tripStartDate is used to spread the installment due dates —
    // pass null if the trip has no start date yet, and each installment is left with no due date.
    public static function createDefaults(Trip $trip, float $totalAmount, string $currency, ?Carbon $tripStartDate = null): void
    {
        if ($totalAmount <= 0) {
            return;
        }

        // Eloquent's pluck() still applies the column's cast, so these come back as
        // PaymentPlanType instances — compare via ->value explicitly rather than relying on
        // Collection::contains()'s loose (==) comparison, which is false between an enum case
        // and a plain string even when the enum backs that exact value.
        $existingTypes = $trip->paymentPlans()->pluck('plan_type')
            ->map(fn ($type) => $type instanceof PaymentPlanType ? $type->value : $type);
        $hasFull = $existingTypes->contains(PaymentPlanType::FULL->value);
        $hasInstallments = $existingTypes->contains(PaymentPlanType::INSTALLMENTS->value);

        DB::transaction(function () use ($trip, $totalAmount, $currency, $tripStartDate, $hasFull, $hasInstallments) {
            if (!$hasFull) {
                self::createFullPlan($trip, $totalAmount, $currency, $tripStartDate);
            }
            if (!$hasInstallments) {
                self::createInstallmentsPlan($trip, $totalAmount, $currency, $tripStartDate);
            }
        });
    }

    private static function createFullPlan(Trip $trip, float $totalAmount, string $currency, ?Carbon $tripStartDate): void
    {
        $plan = PaymentPlan::create([
            'id' => IdGeneratorService::generateId('PLN'),
            'trip_id' => $trip->trip_id,
            'payment_reference' => ReferenceCodeGenerator::generate(),
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'plan_type' => PaymentPlanType::FULL->value,
            'status' => PaymentPlanStatus::ACTIVE->value,
        ]);

        Installment::create([
            'id' => IdGeneratorService::generateId('INS'),
            'payment_plan_id' => $plan->id,
            'sequence' => 1,
            'amount' => $totalAmount,
            'currency' => $currency,
            'due_date' => $tripStartDate,
            'status' => InstallmentStatus::PENDING->value,
        ]);
    }

    private static function createInstallmentsPlan(Trip $trip, float $totalAmount, string $currency, ?Carbon $tripStartDate): void
    {
        $plan = PaymentPlan::create([
            'id' => IdGeneratorService::generateId('PLN'),
            'trip_id' => $trip->trip_id,
            'payment_reference' => ReferenceCodeGenerator::generate(),
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'plan_type' => PaymentPlanType::INSTALLMENTS->value,
            'status' => PaymentPlanStatus::ACTIVE->value,
        ]);

        // Equal thirds — any rounding remainder folds into the last installment so the three
        // amounts always sum exactly to totalAmount (mirrors PaymentPlanController::store's
        // manual "split evenly" behavior on the frontend).
        $base = floor(($totalAmount / self::INSTALLMENT_COUNT) * 100) / 100;
        $amounts = array_fill(0, self::INSTALLMENT_COUNT - 1, $base);
        $amounts[] = round($totalAmount - $base * (self::INSTALLMENT_COUNT - 1), 2);

        $dueDates = self::spreadDueDates($tripStartDate);

        foreach ($amounts as $index => $amount) {
            Installment::create([
                'id' => IdGeneratorService::generateId('INS'),
                'payment_plan_id' => $plan->id,
                'sequence' => $index + 1,
                'amount' => $amount,
                'currency' => $currency,
                'due_date' => $dueDates[$index] ?? null,
                'status' => InstallmentStatus::PENDING->value,
            ]);
        }
    }

    // Three due dates spread between today (booking) and the trip's start date: today, the
    // midpoint, and the start date itself. Without a start date (or one already in the past)
    // there's nothing meaningful to spread across, so every due date is left null — staff can
    // still set them by hand later via the installment plan UI.
    private static function spreadDueDates(?Carbon $tripStartDate): array
    {
        $today = Carbon::today();
        if (!$tripStartDate || $tripStartDate->lessThanOrEqualTo($today)) {
            return [null, null, null];
        }

        $midpoint = $today->copy()->addDays((int) round($today->diffInDays($tripStartDate) / 2));

        return [$today->copy(), $midpoint, $tripStartDate->copy()];
    }
}
