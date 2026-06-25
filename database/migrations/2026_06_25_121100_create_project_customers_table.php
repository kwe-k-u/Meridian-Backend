<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_customers', function (Blueprint $table) {
            $table->string('project_id', 20);
            $table->string('customer_id', 20);
            $table->string('role')->default('primary');
            $table->dateTime('added_at')->useCurrent();

            $table->primary(['project_id', 'customer_id']);
            $table->foreign('project_id')->references('project_id')->on('projects')->onDelete('cascade');
            $table->foreign('customer_id')->references('customer_id')->on('customers')->onDelete('cascade');

            $table->index('project_id', 'idx_project_customers_project');
            $table->index('customer_id', 'idx_project_customers_customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_customers');
    }
};
