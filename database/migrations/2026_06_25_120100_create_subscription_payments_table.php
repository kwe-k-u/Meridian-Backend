<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->string('transaction_id', 20)->primary();
            $table->string('subscription_id', 20);
            $table->string('company_id', 20);
            $table->string('initiated_by', 20)->nullable();

            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->onDelete('cascade');
            $table->foreign('subscription_id')->references('subscription_id')->on('company_subscriptions')->onDelete('restrict');
            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->foreign('initiated_by')->references('user_id')->on('users')->onDelete('set null');

            $table->index('subscription_id', 'idx_subscription_payments_sub');
            $table->index('company_id', 'idx_subscription_payments_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
