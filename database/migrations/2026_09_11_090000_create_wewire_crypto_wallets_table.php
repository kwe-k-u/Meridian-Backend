<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One stablecoin deposit address per company per (asset, chain) pair — mirrors
    // wewire_virtual_accounts' shape (WeWireAccountController::store), but for WeWire's crypto
    // wallets (https://docs.wewire.com/concepts/crypto-wallets) instead of bank/mobile-money
    // virtual accounts. See App\Models\WeWireCryptoWallet.
    public function up(): void
    {
        Schema::create('wewire_crypto_wallets', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('company_id', 20);
            $table->foreign('company_id')->references('company_id')->on('companies')->onDelete('cascade');
            $table->string('asset', 10); // e.g. USDC, USDT
            $table->string('chain', 20); // e.g. BASE, ETHEREUM, POLYGON, TRON
            $table->string('network', 20)->default('MAINNET'); // MAINNET | TESTNET
            $table->string('wewire_wallet_id')->nullable();
            $table->string('deposit_address')->nullable();
            $table->string('status', 20)->default('requested');
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();

            // One wallet per (company, asset, chain) — same "one slot per identity" shape as
            // wewire_virtual_accounts' unique(company_id, currency).
            $table->unique(['company_id', 'asset', 'chain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wewire_crypto_wallets');
    }
};
