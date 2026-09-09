<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row per payout *attempt* (immutable audit log, not updated in place on retry — see
    // WeWirePaymentController::retryDisbursement) — created the moment maybeDisburse() calls
    // WeWireService::initiatePayout(), then updated by the `transaction.status_updated`
    // webhook as WeWire reports on it.
    public function up(): void
    {
        Schema::create('wewire_disbursements', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('wewire_transaction_id')->nullable()->unique();
            $table->string('virtual_account_id', 20);
            $table->foreign('virtual_account_id')->references('id')->on('wewire_virtual_accounts')->onDelete('cascade');
            $table->string('beneficiary_id', 20);
            $table->foreign('beneficiary_id')->references('id')->on('wewire_beneficiaries')->onDelete('cascade');
            // The inbound customer transfer that triggered this payout, if any — retries point
            // back at the same source so the whole attempt history for one payment is visible.
            $table->string('source_inbound_id', 20)->nullable();
            $table->foreign('source_inbound_id')->references('id')->on('wewire_inbound_transactions')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->decimal('fee', 15, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->dateTime('initiated_at');
            $table->dateTime('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wewire_disbursements');
    }
};
