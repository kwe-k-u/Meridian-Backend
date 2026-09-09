<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_accounts', function (Blueprint $table) {
            // Separate from calendar_enabled: calendar_enabled just means "we can see this
            // company's calendar"; meet_tracking_enabled means "and actively auto-detect/
            // schedule Meet calls with it" — both use the same OAuth scope, so this is a
            // feature toggle on top of the connection, not a second OAuth grant.
            $table->boolean('meet_tracking_enabled')->default(false)->after('calendar_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('gmail_accounts', function (Blueprint $table) {
            $table->dropColumn('meet_tracking_enabled');
        });
    }
};
