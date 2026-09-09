<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wewire_virtual_accounts', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('company_id', 20);
            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->string('currency', 3);
            $table->string('wewire_account_id')->nullable();
            $table->string('status', 20)->default('requested');
            $table->string('account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('sort_code')->nullable();
            $table->string('routing_number')->nullable();
            $table->string('fund_handling', 20)->default('hold');
            $table->string('beneficiary_account_id', 20)->nullable();
            $table->foreign('beneficiary_account_id')->references('id')->on('wewire_beneficiaries')->nullOnDelete();
            $table->timestamps();

            // Caps a company at one virtual account per currency — combined with an
            // application-level count check (< 3), this is how "up to 3 accounts" is enforced.
            $table->unique(['company_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wewire_virtual_accounts');
    }
};
