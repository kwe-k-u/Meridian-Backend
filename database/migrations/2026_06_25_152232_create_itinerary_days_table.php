<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itinerary_days', function (Blueprint $table) {
            $table->string('itinerary_day_id', 20)->primary();
            $table->string('itinerary_id', 20);
            $table->integer('day_number');
            $table->date('date')->nullable();
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('location', 200)->nullable();

            $table->foreign('itinerary_id')->references('itinerary_id')->on('itinerary')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_days');
    }
};
