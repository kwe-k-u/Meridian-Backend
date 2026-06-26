<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itinerary', function (Blueprint $table) {
            $table->string('itinerary_id', 20)->primary();
            $table->string('trip_id', 20);
            $table->string('created_by', 20)->nullable();
            $table->string('itinerary_name', 200);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary');
    }
};
