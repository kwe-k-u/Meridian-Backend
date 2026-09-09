<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A disbursement triggered automatically by a single inbound payment (see
    // WeWirePaymentController::maybeDisburse) sets source_inbound_id. A disbursement manually
    // triggered from the dashboard to pay an agency out for everything collected on a trip
    // (see payoutTrip()) sets source_trip_id instead — there's no single inbound transfer to
    // point at, since it can sweep up several installment payments at once.
    public function up(): void
    {
        Schema::table('wewire_disbursements', function (Blueprint $table) {
            $table->string('source_trip_id', 20)->nullable()->after('source_inbound_id');
            $table->foreign('source_trip_id')->references('trip_id')->on('trips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wewire_disbursements', function (Blueprint $table) {
            $table->dropForeign(['source_trip_id']);
            $table->dropColumn('source_trip_id');
        });
    }
};
