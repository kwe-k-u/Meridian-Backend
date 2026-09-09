<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_accounts', function (Blueprint $table) {
            $table->boolean('gmail_enabled')->default(true)->after('status');
            $table->boolean('calendar_enabled')->default(false)->after('gmail_enabled');
        });

        // Every account connected so far went through the Gmail-only flow — the default above
        // already covers new rows, this just makes the backfill explicit for existing ones.
        DB::table('gmail_accounts')->update(['gmail_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('gmail_accounts', function (Blueprint $table) {
            $table->dropColumn(['gmail_enabled', 'calendar_enabled']);
        });
    }
};
