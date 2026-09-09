<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tags a disbursement to the specific trip line item it paid for — a flight, an
    // accommodation booking, or an activity (composite-keyed "{itinerary_day_id}:
    // {destination_id}" since ItineraryDayDestination has no single id column). Null for the
    // pre-existing "pay the agency the whole held balance" flow. line_item_label snapshots a
    // human-readable name at payout time since the underlying itinerary row could later change
    // or be removed.
    public function up(): void
    {
        Schema::table('wewire_disbursements', function (Blueprint $table) {
            $table->string('line_item_type', 20)->nullable()->after('source_trip_id');
            $table->string('line_item_id', 100)->nullable()->after('line_item_type');
            $table->string('line_item_label', 200)->nullable()->after('line_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('wewire_disbursements', function (Blueprint $table) {
            $table->dropColumn(['line_item_type', 'line_item_id', 'line_item_label']);
        });
    }
};
