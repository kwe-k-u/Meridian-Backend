<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('wewire_subcustomer_id')->nullable()->after('preferred_currency');
            $table->string('wewire_kyc_status', 20)->default('not_started')->after('wewire_subcustomer_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['wewire_subcustomer_id', 'wewire_kyc_status']);
        });
    }
};
