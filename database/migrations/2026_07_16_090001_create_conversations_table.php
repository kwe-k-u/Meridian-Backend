<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->string('conversation_id', 20)->primary();
            $table->string('company_id', 20);
            $table->string('channel', 20);
            $table->string('customer_id', 20)->nullable();
            $table->string('trip_id', 20)->nullable();
            $table->string('external_thread_id');
            $table->string('subject')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->cascadeOnDelete();
            $table->foreign('customer_id')->references('customer_id')->on('customers')->nullOnDelete();
            $table->foreign('trip_id')->references('trip_id')->on('trips')->nullOnDelete();

            $table->unique(['company_id', 'channel', 'external_thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
