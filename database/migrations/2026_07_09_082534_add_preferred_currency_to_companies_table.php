<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Which currency this company's amounts should be displayed in across the app
            // (see App\Services\CurrencyService for the supported set + conversion rates).
            // Stored amounts themselves are untouched — this only controls display formatting.
            $table->string('preferred_currency', 3)->default('GHS')->after('city_of_operation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('preferred_currency');
        });
    }
};
