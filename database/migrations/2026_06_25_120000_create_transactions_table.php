<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->string('transaction_id', 20)->primary();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('status')->default('pending');
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference', 255)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_transactions_status');
            $table->index(['transaction_reference'], 'idx_transactions_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
