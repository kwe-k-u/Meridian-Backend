<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_day_destinations', function (Blueprint $table) {
            $table->string('trip_day_id', 20);
            $table->string('destination_id', 20);
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('GHS');
            $table->text('activities')->nullable();
            $table->string('booking_url', 500)->nullable();

            $table->primary(['trip_day_id', 'destination_id']);
            $table->foreign('trip_day_id')->references('trip_day_id')->on('trip_days')->onDelete('cascade');
            $table->foreign('destination_id')->references('destination_id')->on('destinations')->onDelete('restrict');

            $table->index('trip_day_id', 'idx_trip_day_destinations_day');
            $table->index('destination_id', 'idx_trip_day_destinations_dest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_day_destinations');
    }
};
