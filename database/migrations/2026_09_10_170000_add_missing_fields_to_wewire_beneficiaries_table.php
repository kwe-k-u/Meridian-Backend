<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // country/address_line1/city were always required by WeWireBeneficiaryController::store to
    // call WeWire's real createBeneficiary — validated, sent, then silently discarded since
    // they were never in $fillable, so a beneficiary could never be recreated or inspected
    // later. account_category (CHECKING/SAVINGS) is a new field: WeWire's real API requires it
    // for WIRE-settled beneficiaries and previously wasn't collected at all.
    public function up(): void
    {
        Schema::table('wewire_beneficiaries', function (Blueprint $table) {
            $table->string('country', 3)->nullable()->after('currency');
            $table->string('address_line1')->nullable()->after('bank_name');
            $table->string('city')->nullable()->after('address_line1');
            $table->string('account_category', 20)->nullable()->after('routing_number');
        });
    }

    public function down(): void
    {
        Schema::table('wewire_beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['country', 'address_line1', 'city', 'account_category']);
        });
    }
};
