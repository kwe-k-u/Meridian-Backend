<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Every `transaction.pay_in` webhook creates one of these first (keyed by WeWire's own
    // transaction id for idempotency), regardless of whether we can auto-match it to an
    // installment — see WeWirePaymentController::webhook(). Unmatched rows drive the manual
    // reconciliation queue in Settings > Payments.
    public function up(): void
    {
        Schema::create('wewire_inbound_transactions', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('wewire_transaction_id')->unique();
            $table->string('virtual_account_id', 20)->nullable();
            $table->foreign('virtual_account_id')->references('id')->on('wewire_virtual_accounts')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('reference_raw')->nullable();
            $table->string('matched_payment_reference', 8)->nullable();
            $table->string('status', 20)->default('unmatched');
            $table->string('installment_id', 20)->nullable();
            $table->foreign('installment_id')->references('id')->on('installments')->nullOnDelete();
            $table->string('transaction_id', 20)->nullable();
            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->nullOnDelete();
            $table->dateTime('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wewire_inbound_transactions');
    }
};
