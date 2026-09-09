<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wewire_beneficiaries', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('company_id', 20);
            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->string('wewire_beneficiary_id')->nullable();
            $table->string('currency', 3);
            $table->string('account_name');
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('sort_code')->nullable();
            $table->string('routing_number')->nullable();
            $table->string('swift_bic')->nullable();
            $table->string('settlement_method', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wewire_beneficiaries');
    }
};
