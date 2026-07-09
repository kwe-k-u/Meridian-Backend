<?php

namespace App\Services\AI;

use App\Models\Trip;
use Carbon\Carbon;

class TripContextService
{
    public function build(Trip $trip, array $preferences = []): array
    {
        $trip->load(['company', 'customers']);

        $customers  = $trip->customers;
        $company    = $trip->company;
        $startDate  = $trip->start_date ? Carbon::parse($trip->start_date) : null;
        $endDate    = $trip->end_date   ? Carbon::parse($trip->end_date)   : null;
        $durationDays = ($startDate && $endDate)
            ? (int) $startDate->diffInDays($endDate)
            : null;

        // Build a plain-English brief for the LLM
        $brief = $this->buildBrief($trip, $company, $customers, $durationDays, $preferences);

        // Structured spec for grounding
        // Build travelers as a dict (keyed by "traveler_N") — meridian-ai's TripSpec
        // schema types `travelers` as dict|None, not a list, so a PHP sequential
        // array would serialize to a JSON array and fail pydantic validation.
        $travelersDict = [];
        foreach ($customers as $i => $c) {
            $travelersDict["traveler_" . ($i + 1)] = [
                'name'        => trim("{$c->first_name} {$c->last_name}"),
                'nationality' => $c->nationality,
                'notes'       => $c->notes,
            ];
        }

        $spec = [
            'home_city'    => $preferences['start_city'] ?? null,
            'start_date'   => $startDate?->toDateString(),
            'end_date'     => $endDate?->toDateString(),
            'budget'       => $trip->budget ? ['amount' => (float) $trip->budget, 'currency' => 'USD'] : null,
            'travelers'    => $travelersDict ?: null,
            // Extra context fields below — not in TripSpec schema but pydantic ignores them;
            // they are still serialised into the prompt via json_dumps(trip_spec).
            'trip_name'          => $trip->trip_name,
            'description'        => $trip->description,
            'duration_days'      => $durationDays,
            'agency_name'        => $company?->company_name,
            'past_trips_context' => $this->pastTripsContext($company, $trip->trip_id),
        ];

        return ['trip_brief' => $brief, 'trip_spec' => $spec];
    }

    private function buildBrief(
        Trip $trip,
        $company,
        $customers,
        ?int $durationDays,
        array $preferences
    ): string {
        $parts = [];

        $agency = $company?->company_name ?? 'a travel agency';
        $parts[] = "Trip planned by {$agency}: \"{$trip->trip_name}\".";

        if ($trip->description) {
            $parts[] = $trip->description;
        }

        if ($durationDays !== null) {
            $parts[] = "Duration: {$durationDays} days"
                . ($trip->start_date ? " (from {$trip->start_date->toDateString()} to {$trip->end_date->toDateString()})" : '.')
                . '.';
        }

        if ($trip->budget) {
            $parts[] = "Budget: approximately USD {$trip->budget}.";
        }

        $count = $customers->count();
        if ($count > 0) {
            $names = $customers->map(fn($c) => trim("{$c->first_name} {$c->last_name}"))->filter()->implode(', ');
            $parts[] = "Travelers ({$count}): {$names}.";

            $nationalities = $customers->pluck('nationality')->filter()->unique()->values();
            if ($nationalities->isNotEmpty()) {
                $parts[] = 'Nationalities: ' . $nationalities->implode(', ') . '.';
            }

            $notes = $customers->pluck('notes')->filter()->map(fn($n) => trim($n));
            if ($notes->isNotEmpty()) {
                $parts[] = 'Traveler notes: ' . $notes->implode(' | ') . '.';
            }
        }

        foreach (['style', 'priorities', 'notes', 'start_city'] as $key) {
            if (!empty($preferences[$key])) {
                $label = ucfirst(str_replace('_', ' ', $key));
                $value = is_array($preferences[$key])
                    ? implode(', ', $preferences[$key])
                    : $preferences[$key];
                $parts[] = "{$label}: {$value}.";
            }
        }

        return implode(' ', $parts);
    }

    private function pastTripsContext($company, string $excludeTripId): ?string
    {
        if (!$company) {
            return null;
        }

        $past = $company->trips()
            ->where('status', 'completed')
            ->where('trip_id', '!=', $excludeTripId)
            ->orderByDesc('end_date')
            ->limit(5)
            ->get(['trip_name', 'description', 'end_date']);

        if ($past->isEmpty()) {
            return null;
        }

        return $past->map(fn($t) => "\"{$t->trip_name}\" ({$t->end_date})")->implode(', ');
    }
}
