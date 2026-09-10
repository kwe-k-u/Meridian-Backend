<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Flags an inbound "payment" that was recorded via the public pay page's simulated-fallback
    // path (WeWirePaymentController::attemptPublicPayment) rather than a real bank transfer
    // confirmed by WeWire's webhook — same purpose as the is_simulated columns already on
    // wewire_virtual_accounts/wewire_disbursements.
    public function up(): void
    {
        Schema::table('wewire_inbound_transactions', function (Blueprint $table) {
            $table->boolean('is_simulated')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('wewire_inbound_transactions', function (Blueprint $table) {
            $table->dropColumn('is_simulated');
        });
    }
};
