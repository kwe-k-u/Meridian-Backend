<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Mirrors trip_payments' shape exactly: a 1:1 join row linking a generic Transaction to
    // what it paid for (here, an Installment) — see App\Models\TripPayment for the precedent.
    public function up(): void
    {
        Schema::create('installment_payments', function (Blueprint $table) {
            $table->string('transaction_id', 20)->primary();
            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->onDelete('cascade');
            $table->string('installment_id', 20);
            $table->foreign('installment_id')->references('id')->on('installments')->onDelete('cascade');
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
    }
};
