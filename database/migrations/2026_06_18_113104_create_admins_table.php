<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->string('admin_id', 20)->primary();
            $table->string('user_id', 20)->unique();
            $table->string('role')->default('support'); // Managed via Enum
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');

            $table->index('user_id', 'idx_admins_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};