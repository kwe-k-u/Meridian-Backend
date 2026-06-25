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
            $table->string('project_id', 20);
            $table->string('created_by', 20)->nullable();
            $table->string('trip_name', 200);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->foreign('project_id')->references('project_id')->on('projects')->onDelete('cascade');
            $table->foreign('created_by')->references('user_id')->on('users')->onDelete('set null');

            $table->index('status', 'idx_trips_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
