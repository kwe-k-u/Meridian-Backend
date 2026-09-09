<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->string('message_id', 20)->primary();
            $table->string('conversation_id', 20);
            $table->string('external_message_id')->nullable();
            $table->string('direction', 20);
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->text('body_text')->nullable();
            $table->string('snippet', 500)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('conversation_id')->on('conversations')->cascadeOnDelete();

            $table->unique(['conversation_id', 'external_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
