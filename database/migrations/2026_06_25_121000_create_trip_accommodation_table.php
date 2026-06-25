<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_accommodation', function (Blueprint $table) {
            $table->string('accommodation_id', 20)->primary();
            $table->string('trip_id', 20);
            $table->string('accommodation_name', 200);
            $table->string('address', 500)->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->string('room_type', 100)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('GHS');
            $table->string('booking_reference', 50)->nullable();
            $table->string('booking_url', 500)->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            $table->index('trip_id', 'idx_trip_accommodation_trip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_accommodation');
    }
};
