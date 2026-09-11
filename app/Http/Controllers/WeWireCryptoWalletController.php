<?php

namespace App\Http\Controllers;

use App\Enums\CryptoWalletStatus;
use App\Helpers\UserHelper;
use App\Models\WeWireCryptoWallet;
use App\Services\IdGeneratorService;
use App\Services\WeWireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Manages a company's WeWire stablecoin deposit wallets — one per (asset, chain) pair (see
 * WeWireCryptoWallet::SUPPORTED_ASSETS/SUPPORTED_CHAINS). Reached from Settings > Payments,
 * alongside the bank/mobile-money virtual accounts (see WeWireAccountController).
 *
 * Routes: /api/wewire/wallets (authenticated).
 */
class WeWireCryptoWalletController extends Controller
{
    // GET /api/wewire/wallets
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        return response()->json($company->wewireCryptoWallets()->get());
    }

    // GET /api/wewire/wallets/supported-assets — proxies WeWire's own catalog so the frontend
    // doesn't hardcode which (asset, chain) pairs are currently offered.
    public function supportedAssets(WeWireService $wewire): JsonResponse
    {
        return response()->json($wewire->getSupportedWalletAssets());
    }

    // POST /api/wewire/wallets — requests a new (asset, chain) wallet. Same two-phase
    // confirm/fallback pattern as WeWireAccountController::store.
    public function store(Request $request, WeWireService $wewire): JsonResponse
    {
        $company = UserHelper::user_company($request);

        if (!$company->wewire_subcustomer_id) {
            return response()->json(['message' => 'Complete WeWire business registration first.'], 422);
        }

        $validated = $request->validate([
            'asset' => ['required', 'string', Rule::in(WeWireCryptoWallet::SUPPORTED_ASSETS)],
            'chain' => ['required', 'string', Rule::in(WeWireCryptoWallet::SUPPORTED_CHAINS)],
            'confirm_simulated' => 'nullable|boolean',
        ]);

        if ($company->wewireCryptoWallets()->where('asset', $validated['asset'])->where('chain', $validated['chain'])->exists()) {
            return response()->json(['message' => "You already have a {$validated['asset']} wallet on {$validated['chain']}."], 422);
        }

        $result = $wewire->requestWallet(
            $company->wewire_subcustomer_id,
            $validated['asset'],
            $validated['chain'],
            (bool) ($validated['confirm_simulated'] ?? false),
        );

        $source = $result['_wewire_meta']['source'] ?? 'live';

        if ($source === 'simulated_fallback') {
            Log::warning('WeWire crypto wallet request failed — offering simulated fallback', [
                'company_id' => $company->company_id,
                'asset' => $validated['asset'],
                'chain' => $validated['chain'],
                'error' => $result['_wewire_meta']['error'],
            ]);

            return response()->json([
                'requires_confirmation' => true,
                'title' => 'Response from wewire server',
                'message' => 'WeWire did not accept this request. You can proceed with a simulated result instead.',
                'error' => $result['_wewire_meta']['error'],
                'simulated' => Arr::except($result, ['_wewire_meta']),
            ], 409);
        }

        $walletId = $result['id'] ?? $result['walletId'] ?? null;
        if (!$walletId) {
            Log::warning('WeWire crypto wallet request failed', ['company_id' => $company->company_id, 'response' => $result]);
            return response()->json(['message' => $result['message'] ?? 'Could not request that wallet from WeWire.'], 502);
        }

        $wallet = WeWireCryptoWallet::create([
            'id' => IdGeneratorService::generateId('WCW'),
            'company_id' => $company->company_id,
            'asset' => $validated['asset'],
            'chain' => $validated['chain'],
            'network' => $result['network'] ?? 'MAINNET',
            'wewire_wallet_id' => $walletId,
            'deposit_address' => $result['address'] ?? null,
            'status' => strtolower($result['status'] ?? CryptoWalletStatus::REQUESTED->value),
            'is_simulated' => $source === 'simulated_confirmed',
        ]);

        return response()->json($wallet, 201);
    }
}
