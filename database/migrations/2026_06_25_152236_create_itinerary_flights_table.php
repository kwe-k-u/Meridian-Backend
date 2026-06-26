<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itinerary_flights', function (Blueprint $table) {
            $table->string('flight_id', 20)->primary();
            $table->string('itinerary_id', 20);
            $table->string('airline', 100)->nullable();
            $table->string('flight_number', 20)->nullable();
            $table->string('departure_airport', 100)->nullable();
            $table->string('arrival_airport', 100)->nullable();
            $table->datetime('departure_datetime')->nullable();
            $table->datetime('arrival_datetime')->nullable();
            $table->integer('cost')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('booking_reference', 50)->nullable();
            $table->string('booking_url', 500)->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('itinerary_id')->references('itinerary_id')->on('itinerary')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_flights');
    }
};
