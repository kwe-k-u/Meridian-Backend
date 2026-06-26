<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itinerary_day_destinations', function (Blueprint $table) {
            $table->string('itinerary_day_id', 20);
            $table->string('destination_id', 20);
            $table->string('cost', 50)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('activities')->nullable();
            $table->string('booking_url', 500)->nullable();

            $table->primary(['itinerary_day_id', 'destination_id']);
            $table->foreign('itinerary_day_id')->references('itinerary_day_id')->on('itinerary_days')->cascadeOnDelete();
            $table->foreign('destination_id')->references('destination_id')->on('destinations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_day_destinations');
    }
};
