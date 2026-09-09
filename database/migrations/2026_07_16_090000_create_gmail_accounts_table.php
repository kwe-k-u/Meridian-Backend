<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmail_accounts', function (Blueprint $table) {
            $table->string('gmail_account_id', 20)->primary();
            $table->string('company_id', 20)->unique();
            $table->string('connected_by', 20)->nullable();
            $table->string('google_email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->string('history_id')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->cascadeOnDelete();
            $table->foreign('connected_by')->references('user_id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_accounts');
    }
};
