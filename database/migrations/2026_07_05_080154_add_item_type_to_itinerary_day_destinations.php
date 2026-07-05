<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The "item type" picker in AddItemModal.tsx (Activity/Dining/Transfer/Venue) was purely
// client-side UI state with nowhere to persist to — every attachment always rendered with a
// hardcoded "Activity" badge/icon regardless of what was picked. This adds the missing column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itinerary_day_destinations', function (Blueprint $table) {
            $table->string('item_type', 20)->nullable()->default('activity')->after('destination_id');
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_day_destinations', function (Blueprint $table) {
            $table->dropColumn('item_type');
        });
    }
};
