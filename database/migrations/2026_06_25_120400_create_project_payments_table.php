<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_payments', function (Blueprint $table) {
            $table->string('transaction_id', 20)->primary();
            $table->string('project_id', 20);
            $table->text('notes')->nullable();

            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->onDelete('cascade');
            $table->foreign('project_id')->references('project_id')->on('projects')->onDelete('restrict');

            $table->index('project_id', 'idx_project_payments_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_payments');
    }
};
