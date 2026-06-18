<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->string('token', 255)->primary();
            $table->string('company_id', 20);
            $table->string('invited_by', 20);
            $table->string('email', 255);
            $table->string('role')->default('member'); // Managed via Enum
            $table->string('status')->default('pending'); // Managed via Enum
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->foreign('invited_by')->references('user_id')->on('users')->onDelete('cascade');

            // Production optimized indexes
            $table->index('email', 'idx_invitations_email');
            $table->index('status', 'idx_invitations_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};