<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->string('call_id', 20)->primary();
            $table->string('project_id', 20);
            $table->string('organized_by', 20)->nullable();
            $table->string('title', 200)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->string('meeting_link', 500)->nullable();
            $table->text('notes')->nullable();
            $table->longText('transcript')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('project_id')->on('projects')->onDelete('cascade');
            $table->foreign('organized_by')->references('user_id')->on('users')->onDelete('set null');

            $table->index('project_id', 'idx_calls_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
