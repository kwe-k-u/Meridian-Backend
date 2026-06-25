<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_days', function (Blueprint $table) {
            $table->string('trip_day_id', 20)->primary();
            $table->string('trip_id', 20);
            $table->integer('day_number');
            $table->date('date')->nullable();
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('location', 200)->nullable();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            $table->index('trip_id', 'idx_trip_days_trip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_days');
    }
};
