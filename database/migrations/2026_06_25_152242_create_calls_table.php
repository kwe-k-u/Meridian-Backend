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
            $table->string('trip_id', 20);
            $table->string('organized_by', 20)->nullable();
            $table->string('title', 200);
            $table->datetime('started_at')->nullable();
            $table->datetime('ended_at')->nullable();
            $table->string('meeting_link', 500)->nullable();
            $table->text('notes')->nullable();
            $table->text('transcript')->nullable();
            $table->timestamps();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
            $table->foreign('organized_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
