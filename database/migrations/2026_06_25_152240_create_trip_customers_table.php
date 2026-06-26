<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_customers', function (Blueprint $table) {
            $table->string('trip_id', 20);
            $table->string('customer_id', 20);
            $table->string('role', 20);
            $table->timestamp('added_at')->nullable();

            $table->primary(['trip_id', 'customer_id']);
            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
            $table->foreign('customer_id')->references('customer_id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_customers');
    }
};
