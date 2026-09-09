<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('payment_plan_id', 20);
            $table->foreign('payment_plan_id')->references('id')->on('payment_plans')->onDelete('cascade');
            $table->unsignedInteger('sequence');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
