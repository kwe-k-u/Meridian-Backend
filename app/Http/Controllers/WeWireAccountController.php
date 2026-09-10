<?php

namespace App\Http\Controllers;

use App\Enums\FundHandling;
use App\Enums\VirtualAccountStatus;
use App\Helpers\UserHelper;
use App\Models\WeWireBeneficiary;
use App\Models\WeWireVirtualAccount;
use App\Services\IdGeneratorService;
use App\Services\WeWireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Manages a company's WeWire multi-currency virtual accounts — up to
 * WeWireVirtualAccount::MAX_ACCOUNTS_PER_COMPANY (3), one per currency, and each account's
 * hold-vs-disburse setting. Reached from the onboarding wizard (currency selection step) and
 * Settings > Payments.
 *
 * Routes: /api/wewire/accounts (authenticated).
 */
class WeWireAccountController extends Controller
{
    // GET /api/wewire/accounts
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        return response()->json($company->wewireAccounts()->with('beneficiary')->get());
    }

    // POST /api/wewire/accounts — requests a new currency's virtual account. Enforces both
    // "max 3 per company" and "one per currency" at the application level (the migration's
    // unique(company_id, currency) constraint is the last line of defense against a race).
    public function store(Request $request, WeWireService $wewire): JsonResponse
    {
        $company = UserHelper::user_company($request);

        if (!$company->wewire_subcustomer_id) {
            return response()->json(['message' => 'Complete WeWire business registration first.'], 422);
        }

        $validated = $request->validate([
            'currency' => ['required', 'string', Rule::in(WeWireVirtualAccount::SUPPORTED_CURRENCIES)],
            'source_of_funds' => 'nullable|string|max:100',
            'confirm_simulated' => 'nullable|boolean',
        ]);

        $existingCount = $company->wewireAccounts()->count();
        if ($existingCount >= WeWireVirtualAccount::MAX_ACCOUNTS_PER_COMPANY) {
            return response()->json(['message' => 'You can only have up to ' . WeWireVirtualAccount::MAX_ACCOUNTS_PER_COMPANY . ' currency accounts.'], 422);
        }

        if ($company->wewireAccounts()->where('currency', $validated['currency'])->exists()) {
            return response()->json(['message' => "You already have a {$validated['currency']} account."], 422);
        }

        $sourceOfFunds = $validated['currency'] === 'USD' ? ($validated['source_of_funds'] ?? 'BUSINESS_OPERATIONS') : null;
        $result = $wewire->requestVirtualAccount(
            $company->wewire_subcustomer_id,
            $validated['currency'],
            $sourceOfFunds,
            (bool) ($validated['confirm_simulated'] ?? false),
        );

        $source = $result['_wewire_meta']['source'] ?? 'live';

        // WeWire's live API failed and the frontend hasn't yet asked to proceed with a
        // simulated result — hand back both pieces so the "Response from wewire server" popup
        // can let the user decide, without creating anything yet.
        if ($source === 'simulated_fallback') {
            Log::warning('WeWire virtual account request failed — offering simulated fallback', [
                'company_id' => $company->company_id,
                'currency' => $validated['currency'],
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

        if (!isset($result['id'])) {
            Log::warning('WeWire virtual account request failed', ['company_id' => $company->company_id, 'currency' => $validated['currency'], 'response' => $result]);
            return response()->json(['message' => $result['message'] ?? 'Could not request that virtual account from WeWire.'], 502);
        }

        $account = WeWireVirtualAccount::create([
            'id' => IdGeneratorService::generateId('VAC'),
            'company_id' => $company->company_id,
            'currency' => $validated['currency'],
            'wewire_account_id' => $result['id'],
            'status' => strtolower($result['status'] ?? VirtualAccountStatus::REQUESTED->value),
            'fund_handling' => FundHandling::HOLD->value,
            'is_simulated' => $source === 'simulated_confirmed',
        ]);

        return response()->json($account, 201);
    }

    // PATCH /api/wewire/accounts/{account} — sets hold vs. disburse, and (if disbursing) which
    // beneficiary receives the funds.
    public function update(Request $request, WeWireVirtualAccount $account): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($account->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'fund_handling' => ['sometimes', Rule::in(array_column(FundHandling::cases(), 'value'))],
            'beneficiary_account_id' => 'nullable|string|exists:wewire_beneficiaries,id',
        ]);

        if (($validated['fund_handling'] ?? $account->fund_handling->value) === FundHandling::DISBURSE->value) {
            $beneficiaryId = $validated['beneficiary_account_id'] ?? $account->beneficiary_account_id;
            if (!$beneficiaryId) {
                return response()->json(['message' => 'Select a beneficiary account to disburse to.'], 422);
            }
            $beneficiary = WeWireBeneficiary::find($beneficiaryId);
            if (!$beneficiary || $beneficiary->company_id !== $company->company_id || $beneficiary->currency !== $account->currency) {
                return response()->json(['message' => 'That beneficiary is not valid for this account\'s currency.'], 422);
            }
        }

        $account->update($validated);

        return response()->json($account->fresh()->load('beneficiary'));
    }
}
