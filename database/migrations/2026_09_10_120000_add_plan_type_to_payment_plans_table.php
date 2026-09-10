<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Lets a trip carry more than one payment plan at once — specifically the two defaults
    // auto-created when an itinerary is accepted (see App\Services\DefaultPaymentPlanService):
    // a single lump-sum 'full' plan and a 3-installment 'installments' plan, each with their
    // own reference code, so the traveler can pick whichever they'd rather pay through on the
    // /pay/:reference page. Staff can still hand-build a 'custom' plan via
    // PaymentPlanController::store, scoped to its own slot per trip. Existing rows (all
    // manually created before this migration) default to 'custom'.
    public function up(): void
    {
        // trip_id has a foreign key to trips, which needs *some* index on trip_id to exist at
        // all times — so the composite unique (which still covers trip_id as its leading
        // column, satisfying the FK) has to be added before the old single-column unique is
        // dropped, not after, or MySQL refuses the drop mid-migration.
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->string('plan_type', 20)->default('custom')->after('currency');
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->unique(['trip_id', 'plan_type']);
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropUnique(['trip_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_plans', function (Blueprint $table) {
            $table->unique('trip_id');
        });

        Schema::table('payment_plans', function (Blueprint $table) {
            $table->dropUnique(['trip_id', 'plan_type']);
            $table->dropColumn('plan_type');
        });
    }
};
