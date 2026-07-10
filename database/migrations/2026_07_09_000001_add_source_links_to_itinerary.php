<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itinerary', function (Blueprint $table) {
            // JSON object storing the search result URLs that backed the AI's decisions,
            // e.g. { flights_url, hotels_url, events_url }. Visible to agents only.
            $table->json('source_links')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('itinerary', function (Blueprint $table) {
            $table->dropColumn('source_links');
        });
    }
};
