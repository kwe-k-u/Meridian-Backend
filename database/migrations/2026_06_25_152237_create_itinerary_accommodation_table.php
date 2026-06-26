<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itinerary_accommodation', function (Blueprint $table) {
            $table->string('accommodation_id', 20)->primary();
            $table->string('itinerary_id', 20);
            $table->string('accommodation_name', 200);
            $table->string('address', 500)->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->string('room_type', 100)->nullable();
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
        Schema::dropIfExists('itinerary_accommodation');
    }
};
