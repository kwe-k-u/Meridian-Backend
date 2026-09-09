<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Distinguishes "the agency's own payout account" (the only kind that existed until now)
    // from "a specific trip service provider's account" (airline, hotel, activity vendor —
    // see WeWirePaymentController's provider-payout flow). Existing rows default to 'agency'
    // since that was the only use case before this migration. `label` is a free-text display
    // name for provider beneficiaries (e.g. "Emirates Airlines"), since `account_name` is the
    // legal bank-account holder name and may not match how staff want to identify the vendor.
    public function up(): void
    {
        Schema::table('wewire_beneficiaries', function (Blueprint $table) {
            $table->string('beneficiary_type', 20)->default('agency')->after('company_id');
            $table->string('label')->nullable()->after('account_name');
        });
    }

    public function down(): void
    {
        Schema::table('wewire_beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['beneficiary_type', 'label']);
        });
    }
};
