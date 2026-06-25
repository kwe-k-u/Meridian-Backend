<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_flights', function (Blueprint $table) {
            $table->string('flight_id', 20)->primary();
            $table->string('trip_id', 20);
            $table->string('airline', 100)->nullable();
            $table->string('flight_number', 20)->nullable();
            $table->string('departure_airport', 100)->nullable();
            $table->string('arrival_airport', 100)->nullable();
            $table->dateTime('departure_datetime')->nullable();
            $table->dateTime('arrival_datetime')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('GHS');
            $table->string('booking_reference', 50)->nullable();
            $table->string('booking_url', 500)->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            $table->index('trip_id', 'idx_trip_flights_trip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_flights');
    }
};
