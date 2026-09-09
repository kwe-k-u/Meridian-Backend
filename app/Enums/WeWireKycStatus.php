<?php

namespace App\Enums;

/**
 * A company's WeWire business-KYC onboarding status (App\Models\Company.wewire_kyc_status).
 * Mirrors WeWire's sub-customer onboardingStatus field. Virtual accounts can be requested at
 * any status but only progress to ACTIVE once this reaches APPROVED — see
 * `subcustomer.kyc_status_updated` webhook.
 */
enum WeWireKycStatus: string
{
    case NOT_STARTED = 'not_started';
    case DRAFT = 'draft';
    case IN_REVIEW = 'in_review';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case RESUBMISSION = 'resubmission';
}
