<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('google_event_id')->nullable()->unique()->after('meeting_link');
            $table->boolean('excluded')->default(false)->after('google_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropUnique(['google_event_id']);
            $table->dropColumn(['google_event_id', 'excluded']);
        });
    }
};
