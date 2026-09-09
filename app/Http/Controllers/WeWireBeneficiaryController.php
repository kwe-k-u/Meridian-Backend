<?php

namespace App\Http\Controllers;

use App\Helpers\UserHelper;
use App\Models\WeWireBeneficiary;
use App\Services\IdGeneratorService;
use App\Services\WeWireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Manages a company's WeWire payout beneficiaries — bank accounts money can be disbursed to.
 * Two kinds: the agency's own payout account (beneficiary_type='agency', a virtual account
 * can be set to auto-disburse to one — see WeWireAccountController::update) or a specific
 * trip service provider's account (beneficiary_type='provider' — an airline, hotel, or
 * activity vendor, paid out manually via WeWirePaymentController::payoutTrip).
 *
 * Routes: /api/wewire/beneficiaries (authenticated).
 */
class WeWireBeneficiaryController extends Controller
{
    // GET /api/wewire/beneficiaries — optionally filtered by ?type=agency|provider.
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $query = $company->wewireBeneficiaries();
        if ($type = $request->query('type')) {
            $query->where('beneficiary_type', $type);
        }
        return response()->json($query->get());
    }

    // POST /api/wewire/beneficiaries
    public function store(Request $request, WeWireService $wewire): JsonResponse
    {
        $company = UserHelper::user_company($request);

        $validated = $request->validate([
            'beneficiary_type' => ['sometimes', 'string', Rule::in(['agency', 'provider'])],
            'label' => 'nullable|string|max:255',
            'currency' => ['required', 'string', Rule::in(\App\Models\WeWireVirtualAccount::SUPPORTED_CURRENCIES)],
            'account_name' => 'required|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'country' => 'required|string|size:3',
            'account_number' => 'nullable|string|max:50',
            'iban' => 'nullable|string|max:50',
            'sort_code' => 'nullable|string|max:20',
            'routing_number' => 'nullable|string|max:20',
            'swift_bic' => 'nullable|string|max:20',
            'settlement_method' => 'required|string|in:SEPA,FPS,CHAPS,ACH,WIRE,SWIFT',
            'address_line1' => 'required|string|max:255',
            'city' => 'required|string|max:100',
        ]);

        $result = $wewire->createBeneficiary([
            'type' => 'BUSINESS',
            'firstName' => $validated['account_name'],
            'lastName' => '',
            'email' => $request->user()->email,
            'currency' => $validated['currency'],
            'country' => $validated['country'],
            'addressLine1' => $validated['address_line1'],
            'city' => $validated['city'],
            'accountDetails' => array_filter([
                'type' => 'BANK_ACCOUNT',
                'currency' => $validated['currency'],
                'settlementMethod' => $validated['settlement_method'],
                'accountName' => $validated['account_name'],
                'accountNumber' => $validated['account_number'] ?? null,
                'iban' => $validated['iban'] ?? null,
                'sortCode' => $validated['sort_code'] ?? null,
                'routingNumber' => $validated['routing_number'] ?? null,
                'swiftBic' => $validated['swift_bic'] ?? null,
                'bankName' => $validated['bank_name'] ?? null,
            ]),
        ]);

        if (!isset($result['id'])) {
            Log::warning('WeWire beneficiary creation failed', ['company_id' => $company->company_id, 'response' => $result]);
            return response()->json(['message' => $result['message'] ?? 'Could not add that beneficiary with WeWire.'], 502);
        }

        $beneficiary = WeWireBeneficiary::create([
            'id' => IdGeneratorService::generateId('WBN'),
            'company_id' => $company->company_id,
            'beneficiary_type' => $validated['beneficiary_type'] ?? 'agency',
            'label' => $validated['label'] ?? null,
            'wewire_beneficiary_id' => $result['id'],
            'currency' => $validated['currency'],
            'account_name' => $validated['account_name'],
            'bank_name' => $validated['bank_name'] ?? null,
            'account_number' => $validated['account_number'] ?? null,
            'iban' => $validated['iban'] ?? null,
            'sort_code' => $validated['sort_code'] ?? null,
            'routing_number' => $validated['routing_number'] ?? null,
            'swift_bic' => $validated['swift_bic'] ?? null,
            'settlement_method' => $validated['settlement_method'],
        ]);

        return response()->json($beneficiary, 201);
    }
}
