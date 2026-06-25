<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->string('customer_id', 20)->primary();
            $table->string('company_id', 20);
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('nationality', 50)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('passport_number', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->index('company_id', 'idx_customers_company');
            $table->index('email', 'idx_customers_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
