<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->string('subscription_id', 20)->primary();
            $table->string('company_id', 20);
            $table->string('tier_id', 20);
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->string('status')->default('active'); // Managed via Enum
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->foreign('tier_id')->references('tier_id')->on('subscription_tiers')->onDelete('cascade');

            // Optimizing index for fetching active subscriptions rapidly
            $table->index(['company_id', 'status'], 'idx_company_sub_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
    }
};