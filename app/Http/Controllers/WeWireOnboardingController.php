<?php

namespace App\Http\Controllers;

use App\Enums\WeWireKycStatus;
use App\Helpers\UserHelper;
use App\Services\WeWireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Registers a company as a WeWire BUSINESS sub-customer and submits its business KYC, so it
 * can go on to request virtual accounts (see WeWireAccountController). Reached from the
 * post-signup onboarding wizard (skippable) and again later from Settings > Payments.
 *
 * Routes: /api/wewire/subcustomer, /api/wewire/subcustomer/kyc (authenticated).
 */
class WeWireOnboardingController extends Controller
{
    // POST /api/wewire/subcustomer — creates the WeWire sub-customer record for this company.
    // Safe to call again if it already exists (no-op, just returns current state) since the
    // onboarding wizard may be re-entered after a partial attempt.
    public function registerSubCustomer(Request $request, WeWireService $wewire): JsonResponse
    {
        $company = UserHelper::user_company($request);

        if ($company->wewire_subcustomer_id) {
            return response()->json(['wewire_subcustomer_id' => $company->wewire_subcustomer_id, 'wewire_kyc_status' => $company->wewire_kyc_status]);
        }

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'country' => 'required|string|size:3', // ISO 3166-1 alpha-3
            'business_type' => 'required|string|in:GENERAL_BUSINESS,SOLE_PROPRIETORSHIP',
        ]);

        $result = $wewire->createSubCustomer([
            'businessName' => $company->company_name,
            'businessType' => $validated['business_type'],
            'email' => $validated['email'],
            'country' => $validated['country'],
            'purpose' => ['COLLECTION', 'PAYOUT'],
            'referenceId' => $company->company_id,
        ]);

        $subCustomerId = $result['id'] ?? null;
        if (!$subCustomerId) {
            Log::warning('WeWire sub-customer creation failed', ['company_id' => $company->company_id, 'response' => $result]);
            return response()->json(['message' => $result['message'] ?? 'Could not register with WeWire.'], 502);
        }

        $company->update([
            'wewire_subcustomer_id' => $subCustomerId,
            'wewire_kyc_status' => WeWireKycStatus::DRAFT->value,
        ]);

        return response()->json(['wewire_subcustomer_id' => $subCustomerId, 'wewire_kyc_status' => $company->wewire_kyc_status]);
    }

    // POST /api/wewire/subcustomer/kyc — chains WeWire's multi-step business KYC flow
    // (documents -> company details/questionnaire -> beneficial owner -> submit for review)
    // into one call from a single onboarding form. If any step fails partway, the company's
    // wewire_kyc_status stays DRAFT so the wizard can be safely retried.
    public function submitKyc(Request $request, WeWireService $wewire): JsonResponse
    {
        $company = UserHelper::user_company($request);

        if (!$company->wewire_subcustomer_id) {
            return response()->json(['message' => 'Register as a WeWire sub-customer first.'], 422);
        }

        $validated = $request->validate([
            'company.registrationNumber' => 'required|string|max:50',
            'company.incorporatedOn' => 'required|date',
            'company.taxId' => 'nullable|string|max:50',
            'company.phone' => 'required|string|max:20',
            'company.website' => 'nullable|string|max:255',
            'company.address' => 'required|array',
            'company.address.addressLine1' => 'required|string|max:255',
            'company.address.city' => 'required|string|max:100',
            'company.address.stateProvince' => 'nullable|string|max:100',
            'company.address.postalCode' => 'nullable|string|max:20',
            'company.address.country' => 'required|string|size:3',
            'questionnaire.businessDescription' => 'required|string|max:1000',
            'questionnaire.expectedMonthlyVolume' => 'required|string|max:50',
            'documents' => 'required|array|min:1',
            'documents.*.docType' => 'required|string|max:50',
            'documents.*.file' => 'required|string',
            'beneficial_owner' => 'required|array',
            'beneficial_owner.firstName' => 'required|string|max:100',
            'beneficial_owner.lastName' => 'required|string|max:100',
            'beneficial_owner.dateOfBirth' => 'required|date',
            'beneficial_owner.gender' => 'required|string|in:M,F',
            'beneficial_owner.nationality' => 'required|string|size:3',
            'beneficial_owner.email' => 'required|email',
            'beneficial_owner.phone' => 'required|string|max:20',
            'beneficial_owner.address' => 'required|array',
            'beneficial_owner.idType' => 'required|string|max:30',
            'beneficial_owner.idFileFront' => 'required|string',
            'beneficial_owner.idFileBack' => 'nullable|string',
            'beneficial_owner.shareSize' => 'required|integer|min:1|max:100',
        ]);

        $subCustomerId = $company->wewire_subcustomer_id;

        // 1. Upload each document, collecting fileIds for the questionnaire.
        $fileIds = [];
        foreach ($validated['documents'] as $doc) {
            $uploaded = $wewire->uploadDocument($subCustomerId, $doc['docType'], $doc['file']);
            if (!isset($uploaded['fileId'])) {
                Log::warning('WeWire document upload failed', ['company_id' => $company->company_id, 'docType' => $doc['docType'], 'response' => $uploaded]);
                return response()->json(['message' => 'Could not upload one of your documents. Please try again.'], 502);
            }
            $fileIds[$doc['docType']] = $uploaded['fileId'];
        }

        // 2. Submit company details + questionnaire.
        $kycResult = $wewire->submitBusinessKyc($subCustomerId, $validated['company'], array_merge(
            $validated['questionnaire'],
            $fileIds,
        ));
        if (isset($kycResult['error']) || isset($kycResult['message']) && !isset($kycResult['data'])) {
            // WeWire's docs don't show a definitive success envelope for this step; treat an
            // explicit error/message-without-data response as failure, anything else as ok.
            if (isset($kycResult['error'])) {
                Log::warning('WeWire business KYC submission failed', ['company_id' => $company->company_id, 'response' => $kycResult]);
                return response()->json(['message' => 'Could not submit your business details to WeWire.'], 502);
            }
        }

        // 3. Add the beneficial owner.
        $ownerResult = $wewire->addBeneficialOwner($subCustomerId, array_merge(
            ['categories' => ['ubo', 'director']],
            $validated['beneficial_owner'],
        ));
        if (isset($ownerResult['error'])) {
            Log::warning('WeWire beneficial owner submission failed', ['company_id' => $company->company_id, 'response' => $ownerResult]);
            return response()->json(['message' => 'Could not submit the beneficial owner details.'], 502);
        }

        // 4. Submit for review.
        $submitResult = $wewire->submitKycForReview($subCustomerId);
        $onboardingStatus = $submitResult['onboardingStatus'] ?? null;

        $company->update([
            'wewire_kyc_status' => $onboardingStatus === 'IN_REVIEW' ? WeWireKycStatus::IN_REVIEW->value : WeWireKycStatus::DRAFT->value,
        ]);

        if ($onboardingStatus !== 'IN_REVIEW') {
            Log::warning('WeWire KYC submit-for-review did not return IN_REVIEW', ['company_id' => $company->company_id, 'response' => $submitResult]);
            return response()->json(['message' => $submitResult['message'] ?? 'Your details were saved, but WeWire could not accept them for review yet.', 'wewire_kyc_status' => $company->wewire_kyc_status], 422);
        }

        return response()->json(['wewire_kyc_status' => $company->wewire_kyc_status, 'hosted_owner_kyc_link' => $ownerResult['kycLink'] ?? null]);
    }

    // GET /api/wewire/subcustomer — current onboarding state, for the wizard/Settings page to
    // decide what to show.
    public function status(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        return response()->json([
            'wewire_subcustomer_id' => $company->wewire_subcustomer_id,
            'wewire_kyc_status' => $company->wewire_kyc_status,
        ]);
    }
}
