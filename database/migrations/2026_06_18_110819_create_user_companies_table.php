<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_companies', function (Blueprint $table) {
            $table->string('user_id', 20);
            $table->string('company_id', 20);
            $table->string('role')->default('member'); // Managed via Enum
            $table->boolean('is_default')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('joined_at')->useCurrent();

            $table->primary(['user_id', 'company_id']);
            
            $table->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');

            // Indexes for optimizing role searches and performance
            $table->index('role', 'idx_user_company_role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_companies');
    }
};
