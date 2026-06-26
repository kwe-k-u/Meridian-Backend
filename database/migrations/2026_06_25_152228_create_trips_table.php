<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->string('trip_id', 20)->primary();
            $table->string('company_id', 20);
            $table->string('created_by', 20)->nullable();
            $table->string('trip_name', 200);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('budget', 50)->nullable();
            $table->string('status', 20)->default('inquiry');
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
