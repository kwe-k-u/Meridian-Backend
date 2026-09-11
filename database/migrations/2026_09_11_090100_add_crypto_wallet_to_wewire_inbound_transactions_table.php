<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A crypto deposit reuses the exact same inbound-transaction + reconciliation-queue
    // machinery a bank transfer already goes through (WeWirePaymentController::handlePayIn/
    // matchInbound) rather than a parallel table — see WeWireInboundTransaction. Unlike a bank
    // transfer, a crypto deposit essentially never carries a matchable reference/memo (most
    // chains WeWire supports have no memo field at all), so `matched_payment_reference` stays
    // null and it lands straight in the UNMATCHED queue for staff to assign by hand — same UI,
    // no new screen needed. tx_hash is crypto-specific provenance, kept separately from
    // reference_raw (which is free-text bank narration on the transfer path).
    public function up(): void
    {
        Schema::table('wewire_inbound_transactions', function (Blueprint $table) {
            $table->string('crypto_wallet_id', 20)->nullable()->after('virtual_account_id');
            $table->foreign('crypto_wallet_id')->references('id')->on('wewire_crypto_wallets')->nullOnDelete();
            $table->string('tx_hash')->nullable()->after('crypto_wallet_id');
        });
    }

    public function down(): void
    {
        Schema::table('wewire_inbound_transactions', function (Blueprint $table) {
            $table->dropForeign(['crypto_wallet_id']);
            $table->dropColumn(['crypto_wallet_id', 'tx_hash']);
        });
    }
};
