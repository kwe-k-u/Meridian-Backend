<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ItineraryController::addFlight/addAccommodation have always validated `status` as
// `nullable`, but neither the original migrations for these two tables gave the column a
// default — the exact same class of bug fixed for destinations.country earlier. Nothing
// (the manual add-flight/add-stay forms, and now the new SerpApi search-and-select flow)
// has ever sent a status, so every single addFlight/addAccommodation call has always 500'd.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itinerary_flights', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
        Schema::table('itinerary_accommodation', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_flights', function (Blueprint $table) {
            $table->string('status', 20)->default(null)->change();
        });
        Schema::table('itinerary_accommodation', function (Blueprint $table) {
            $table->string('status', 20)->default(null)->change();
        });
    }
};
