<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->string('project_id', 20)->primary();
            $table->string('company_id', 20);
            $table->string('created_by', 20)->nullable();
            $table->string('project_name', 200);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('budget', 50)->nullable();
            $table->string('status')->default('inquiry');
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->foreign('created_by')->references('user_id')->on('users')->onDelete('set null');

            $table->index(['company_id'], 'idx_projects_company');
            $table->index(['status'], 'idx_projects_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
