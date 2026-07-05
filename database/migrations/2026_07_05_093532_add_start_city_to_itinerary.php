<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The city/place a trip's itinerary departs from — lets the flight-search modal default its
// "From" field and the stay-search modal default its destination query instead of starting
// blank every time (see AddFlightModal.tsx/AddStayModal.tsx).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itinerary', function (Blueprint $table) {
            $table->string('start_city', 100)->nullable()->after('itinerary_name');
        });
    }

    public function down(): void
    {
        Schema::table('itinerary', function (Blueprint $table) {
            $table->dropColumn('start_city');
        });
    }
};
