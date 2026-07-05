<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model for the `airports` table.
 *
 * Purpose: A shared reference table of real-world airports (IATA code, name, city, country),
 * seeded from the OpenFlights dataset (see database/seeders/AirportSeeder.php) and used to let
 * agents search flights by city/country name instead of memorizing IATA codes — see
 * AirportController::search() and the frontend's AddFlightModal.
 *
 * Read-only from the API's perspective: there's no store/update/destroy — this table is
 * refreshed by re-running the seeder, not edited by users.
 *
 * @property string $iata_code Unique 3-letter IATA airport code (primary key).
 */
class Airport extends Model
{
    protected $table = 'airports';
    protected $primaryKey = 'iata_code';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'iata_code',
        'name',
        'city',
        'country',
    ];
}
