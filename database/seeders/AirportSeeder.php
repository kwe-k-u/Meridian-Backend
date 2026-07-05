<?php

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Seeder;

/**
 * Loads the airports table from database/data/airports.dat — a copy of the OpenFlights
 * airport database (https://github.com/jpatokal/openflights), a public-domain dataset of
 * ~7,700 airports worldwide. Only rows with a real 3-letter IATA code are kept (~6,000 of
 * them) since that's the only kind of airport SerpApi's flight search can use.
 *
 * Re-runnable: upserts by iata_code, so running this again (e.g. after refreshing the data
 * file) updates existing rows instead of duplicating them.
 *
 * Run with: php artisan db:seed --class=AirportSeeder
 */
class AirportSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/airports.dat');
        if (!file_exists($path)) {
            $this->command?->warn("Airport data file not found at {$path} — skipping.");
            return;
        }

        $handle = fopen($path, 'r');
        $seen = [];
        $batch = [];
        $total = 0;

        // OpenFlights columns (no header row): id, name, city, country, IATA, ICAO, lat, lon,
        // altitude, timezone, DST, tz-database-timezone, type, source.
        while (($row = fgetcsv($handle)) !== false) {
            $iata = strtoupper((string) ($row[4] ?? ''));
            // Missing codes are stored as the literal string "\N" in this dataset.
            if (strlen($iata) !== 3 || $iata === '\N' || isset($seen[$iata])) {
                continue;
            }
            $seen[$iata] = true;
            $batch[] = [
                'iata_code' => $iata,
                'name' => $row[1] ?? '',
                'city' => $row[2] ?? '',
                'country' => $row[3] ?? '',
            ];
            if (count($batch) >= 500) {
                Airport::upsert($batch, ['iata_code'], ['name', 'city', 'country']);
                $total += count($batch);
                $batch = [];
            }
        }
        if ($batch) {
            Airport::upsert($batch, ['iata_code'], ['name', 'city', 'country']);
            $total += count($batch);
        }
        fclose($handle);

        $this->command?->info("Seeded {$total} airports.");
    }
}
