<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Same audit-trail purpose as the is_simulated columns on wewire_virtual_accounts/
    // wewire_disbursements/wewire_inbound_transactions — now that WeWireService::
    // createBeneficiary() also goes through the live-call fallback (see
    // WeWireBeneficiaryController::store), a beneficiary can be created from a simulated result.
    public function up(): void
    {
        Schema::table('wewire_beneficiaries', function (Blueprint $table) {
            $table->boolean('is_simulated')->default(false)->after('settlement_method');
        });
    }

    public function down(): void
    {
        Schema::table('wewire_beneficiaries', function (Blueprint $table) {
            $table->dropColumn('is_simulated');
        });
    }
};
