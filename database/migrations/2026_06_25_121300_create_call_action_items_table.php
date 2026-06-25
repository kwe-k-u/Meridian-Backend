<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_action_items', function (Blueprint $table) {
            $table->string('action_item_id', 20)->primary();
            $table->string('call_id', 20);
            $table->text('description');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('call_id')->references('call_id')->on('calls')->onDelete('cascade');
            $table->index('call_id', 'idx_call_action_items_call');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_action_items');
    }
};
