<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('trip_id', 20)->unique();
            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            // Customer-quoted lookup/reconciliation code — 5 uppercase letters + 3 digits by
            // default (e.g. ABCDE123), auto-generated but editable by agency staff. See
            // App\Services\ReferenceCodeGenerator.
            $table->string('payment_reference', 8)->unique();
            $table->decimal('total_amount', 15, 2);
            $table->string('currency', 3);
            $table->string('status', 20)->default('draft');
            $table->string('created_by', 20)->nullable();
            $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
