<?php

namespace App\Services\AI;

use App\Enums\ItineraryStatus;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryDayDestination;
use App\Services\IdGeneratorService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ItineraryBuilderService
{
    public function buildAll(
        array  $aiResponse,
        string $tripId,
        string $createdBy,
        ?string $startCity,
        ?Carbon $startDate,
        ?Carbon $endDate
    ): Collection {
        $options = $aiResponse['options'] ?? [];

        $built = collect();

        foreach ($options as $option) {
            $itinerary = $this->buildOne(
                $option,
                $tripId,
                $createdBy,
                $startCity,
                $startDate,
                $endDate
            );
            $built->push($itinerary);
        }

        return $built;
    }

    private function buildOne(
        array  $option,
        string $tripId,
        string $createdBy,
        ?string $startCity,
        ?Carbon $startDate,
        ?Carbon $endDate
    ): Itinerary {
        $letter    = strtoupper($option['letter'] ?? 'A');
        $name      = $option['name'] ?? "Option {$letter}";
        $summary   = $option['summary'] ?? null;
        $totalCost = $option['total_cost'] ?? null;

        $descParts = [];
        if ($summary) {
            $descParts[] = $summary;
        }
        if ($totalCost && isset($totalCost['amount'])) {
            $currency = $totalCost['currency'] ?? 'USD';
            $est      = ($totalCost['is_estimate'] ?? false) ? ' (estimate)' : '';
            $descParts[] = "Total cost: {$totalCost['amount']} {$currency}{$est}.";
        }

        $itinerary = Itinerary::create([
            'itinerary_id'   => IdGeneratorService::generateId('ITN'),
            'trip_id'        => $tripId,
            'created_by'     => $createdBy,
            'itinerary_name' => $name,
            'start_city'     => $startCity,
            'description'    => implode(' ', $descParts) ?: null,
            'start_date'     => $startDate?->toDateString(),
            'end_date'       => $endDate?->toDateString(),
            'status'         => ItineraryStatus::DRAFT->value,
        ]);

        $days = $option['days'] ?? [];
        ksort($days, SORT_NATURAL); // "1","2","10" sorts correctly; also handles "day_1" prefix

        foreach ($days as $dayKey => $dayData) {
            // dayKey is "day_1", "day_2", etc.
            $dayNumber = (int) str_replace('day_', '', $dayKey);
            $date      = $startDate
                ? $startDate->copy()->addDays($dayNumber - 1)->toDateString()
                : null;

            $day = ItineraryDay::create([
                'itinerary_day_id' => IdGeneratorService::generateId('ITD'),
                'itinerary_id'     => $itinerary->itinerary_id,
                'day_number'       => $dayNumber,
                'date'             => $date,
                'title'            => $dayData['title'] ?? "Day {$dayNumber}",
                'description'      => null,
                'location'         => null,
            ]);

            $destinations = $dayData['destinations'] ?? [];
            foreach ($destinations as $destName => $destData) {
                $activities    = $destData['activities'] ?? [];
                $estimatedCost = $destData['estimated_cost'] ?? null;
                // AI returns estimated_cost as {amount, currency, is_estimate} — extract the number.
                $costAmount  = is_array($estimatedCost) ? ($estimatedCost['amount'] ?? null) : (is_numeric($estimatedCost) ? (float) $estimatedCost : null);
                $costCurrency = is_array($estimatedCost) ? ($estimatedCost['currency'] ?? 'USD') : 'USD';

                $destination = $this->findOrCreateDestination($destName);

                ItineraryDayDestination::create([
                    'itinerary_day_id' => $day->itinerary_day_id,
                    'destination_id'   => $destination->destination_id,
                    'item_type'        => 'activity',
                    'cost'             => $costAmount,
                    'currency'         => $costCurrency,
                    'activities'       => !empty($activities) ? implode("\n", $activities) : null,
                    'booking_url'      => null,
                ]);
            }
        }

        return $itinerary->load([
            'itineraryDays.destinations.destination',
            'itineraryFlights',
            'itineraryAccommodation',
        ]);
    }

    private function findOrCreateDestination(string $name): Destination
    {
        // Case-insensitive match against existing destinations to avoid duplicates.
        $existing = Destination::whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->first();

        if ($existing) {
            return $existing;
        }

        return Destination::create([
            'destination_id' => IdGeneratorService::generateId('DST'),
            'name'           => trim($name),
            'country'        => null,
            'url'            => null,
        ]);
    }
}
