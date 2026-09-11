<?php

namespace App\Models;

use App\Enums\CryptoWalletStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `wewire_crypto_wallets` table.
 *
 * Purpose: A stablecoin deposit address for a company — one per (asset, chain) pair (see
 * unique(company_id, asset, chain)), mirroring how WeWireVirtualAccount is one per
 * (company_id, currency). Customers pay by sending USDC/USDT directly to `deposit_address`;
 * confirmation arrives via the `subcustomer.wallet.deposit.received` webhook, reconciled the
 * same way a bank transfer is — see WeWireInboundTransaction's crypto_wallet_id/tx_hash.
 *
 * @property string $id Unique identifier.
 * @property string $company_id Foreign key to the owning company.
 * @property CryptoWalletStatus $status
 */
class WeWireCryptoWallet extends Model
{
    use HasFactory;

    // Assets/chains WeWire issues deposit wallets for (https://docs.wewire.com/concepts/
    // crypto-wallets) — stablecoins only, each pegged ~1:1 to USD, so a wallet is only ever
    // offered alongside a USD payment plan (see WeWirePaymentController::buildLookupResponse).
    public const SUPPORTED_ASSETS = ['USDC', 'USDT'];
    public const SUPPORTED_CHAINS = ['BASE', 'ETHEREUM', 'POLYGON', 'TRON'];

    protected $table = 'wewire_crypto_wallets';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'asset',
        'chain',
        'network',
        'wewire_wallet_id',
        'deposit_address',
        'status',
        'is_simulated',
    ];

    protected $casts = [
        'status' => CryptoWalletStatus::class,
        'is_simulated' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function inboundTransactions(): HasMany
    {
        return $this->hasMany(WeWireInboundTransaction::class, 'crypto_wallet_id', 'id');
    }
}
