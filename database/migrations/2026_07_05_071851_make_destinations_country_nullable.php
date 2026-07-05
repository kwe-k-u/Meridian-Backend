<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DestinationController::store/update have always validated `country` as `nullable` (the
// quick-add flow in AddItemModal.tsx creates a destination with just a name), but the
// original migration declared the column NOT NULL with no default — any name-only create
// crashed with a MySQL "doesn't have a default value" error. This aligns the column with the
// validation rule that was already there.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->string('country', 100)->nullable(false)->change();
        });
    }
};
