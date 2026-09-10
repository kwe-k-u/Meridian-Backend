<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Flags a row as coming from WeWireService::liveCall()'s simulated fallback (the live API
    // call failed and the dashboard user accepted a fabricated result via the "Response from
    // wewire server" popup) rather than a genuine WeWire response — see WeWireAccountController
    // ::store and WeWirePaymentController::initiateDisbursement.
    public function up(): void
    {
        Schema::table('wewire_virtual_accounts', function (Blueprint $table) {
            $table->boolean('is_simulated')->default(false)->after('status');
        });

        Schema::table('wewire_disbursements', function (Blueprint $table) {
            $table->boolean('is_simulated')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('wewire_virtual_accounts', function (Blueprint $table) {
            $table->dropColumn('is_simulated');
        });

        Schema::table('wewire_disbursements', function (Blueprint $table) {
            $table->dropColumn('is_simulated');
        });
    }
};
