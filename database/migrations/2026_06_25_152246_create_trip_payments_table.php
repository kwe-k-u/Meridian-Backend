<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_payments', function (Blueprint $table) {
            $table->string('transaction_id', 20)->primary();
            $table->string('trip_id', 20);
            $table->text('notes')->nullable();

            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->cascadeOnDelete();
            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_payments');
    }
};
