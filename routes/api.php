<?php

use App\Http\Controllers\AirportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySubscriptionController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoInviteController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\GmailController;
use App\Http\Controllers\GmailThreadController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ItineraryController;
use App\Http\Controllers\MoolrePaymentController;
use App\Http\Controllers\PaymentPlanController;
use App\Http\Controllers\PaystackPaymentController;
use App\Http\Controllers\SubscriptionTierController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeWireAccountController;
use App\Http\Controllers\WeWireBeneficiaryController;
use App\Http\Controllers\WeWireOnboardingController;
use App\Http\Controllers\WeWirePaymentController;
use Illuminate\Support\Facades\Route;

// All routes below are prefixed with /api automatically (see bootstrap/app.php).
//
// Two groups:
//  1. Public auth routes (login, register, password reset) — no token required.
//  2. Everything else, behind the `auth:sanctum` middleware — requires a valid Bearer
//     token (issued by AuthController::login/registerCompany/handleGoogleLogin) in the
//     Authorization header. Almost every authenticated controller then scopes its data
//     to UserHelper::user_company($request), i.e. the caller's *active* company.
//
// Note: AdminController exists under app/Http/Controllers but has no routes registered
// here — it's not reachable over HTTP yet.

// ── [Auth Routes] ──
Route::prefix('auth')->group(function() {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register-company', [AuthController::class, 'registerCompany']);
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink']);
    Route::post('/reset-password', [AuthController::class, 'resetForgotPassword']);
});

// ── [Moolre Webhook] ── Public — Moolre has no way to send our bearer token. Not trusted
// blindly: MoolrePaymentController::webhook() re-verifies status with Moolre itself.
Route::post('/payments/moolre/webhook', [MoolrePaymentController::class, 'webhook']);

// ── [WeWire Webhook] ── Public — signature-verified (see WeWireService::verifyWebhookSignature)
// rather than trusted on the URL alone. See WeWirePaymentController docblock.
Route::post('/payments/wewire/webhook', [WeWirePaymentController::class, 'webhook']);

// ── [Paystack Webhook] ── Public — Paystack has no way to send our bearer token. Verified via
// the x-paystack-signature HMAC header (see PaystackPaymentController::webhook), which also
// re-verifies status with Paystack itself before trusting anything in the payload.
Route::post('/payments/paystack/webhook', [PaystackPaymentController::class, 'webhook']);

// ── [Currency Rates] ── Public — static, non-sensitive conversion table (see
// App\Services\CurrencyService). No auth needed, and the public traveler view needs it too.
Route::get('/currency-rates', [CurrencyController::class, 'index']);

// ── [Gmail OAuth Callback] ── Public — Google redirects the user's browser here directly
// after consent, so there's no Sanctum session on this hop. Not trusted blindly: it only acts
// on a `state` value GmailController::connect() cached server-side (see class docblock).
Route::get('/gmail/callback', [GmailController::class, 'callback']);

// ── [Public Traveler Routes] ── Reachable via the shareable /travel/{tripId} link — no
// Meridian account required. trip_id itself (an unguessable generated ID, never sequential)
// is the "credential" here, the same trust model the frontend's /travel/:tripId route already
// uses. Scoped narrowly: read-only trip + cost view, and Moolre trip-payment initiation/status
// — nothing here can mutate a trip/itinerary or reach another company's data.
Route::prefix('public')->group(function () {
    Route::get('/trips/{trip}', [TripController::class, 'publicShow']);
    Route::get('/trips/{trip}/costs', [TripController::class, 'publicCosts']);
    Route::post('/trips/{trip}/itineraries/{itinerary}/accept', [TripController::class, 'acceptItinerary']);
    Route::post('/payments/moolre/trip', [MoolrePaymentController::class, 'initiatePublicTripPayment']);
    Route::get('/payments/moolre/{transaction}/status', [MoolrePaymentController::class, 'publicStatus']);

    // ── [WeWire Public Collection Page] ── Reachable via the shareable /pay/{reference} link
    // — no Meridian account required. See WeWirePaymentController::lookupPublic.
    Route::get('/payments/wewire/lookup/{reference}', [WeWirePaymentController::class, 'lookupPublic']);
    // "Proceed with payment" button — only responds while WeWire is in simulation mode (see
    // WeWirePaymentController::simulatePublicPayment). WeWire has no real hosted checkout, so
    // this stands in for someone actually transferring the money.
    Route::post('/payments/wewire/simulate/{reference}', [WeWirePaymentController::class, 'simulatePublicPayment']);

    // ── [Demo Invite Routes] ── Landing-page validation + acceptance for a demo-invite link
    // emailed by DemoInviteController::store() (registered below, behind auth:sanctum).
    Route::get('/demo-invites/{token}', [DemoInviteController::class, 'show']);
    Route::post('/demo-invites/{token}', [DemoInviteController::class, 'accept']);
});

// ── [Authenticated Routes] ──
Route::middleware('auth:sanctum')->group(function () {
    // ── [Dashboard] ──
    Route::get('/dashboard', DashboardController::class);

    // ── [Destination Routes] ──
    Route::apiResource('destinations', DestinationController::class);

    // ── [Airport Routes] ── Read-only city/country → IATA code lookup for flight search.
    Route::get('/airports/search', [AirportController::class, 'search']);

    // ── [Customer Routes] ──
    Route::apiResource('customers', CustomerController::class);

    // ── [Trip Routes] ──
    Route::prefix('trips')->group(function () {
        Route::get('/', [TripController::class, 'index']);
        Route::post('/', [TripController::class, 'store']);
        Route::get('/{trip}', [TripController::class, 'show']);
        Route::put('/{trip}', [TripController::class, 'update']);
        Route::get('/{trip}/costs', [TripController::class, 'costs']);
        Route::post('/{trip}/generate-itinerary', [TripController::class, 'generateItinerary']);
        Route::patch('/{trip}/status', [TripController::class, 'updateStatus']);
        Route::delete('/{trip}', [TripController::class, 'destroy']);
        Route::post('/{trip}/customers', [TripController::class, 'addCustomer']);
        Route::delete('/{trip}/customers/{customer}', [TripController::class, 'removeCustomer']);
        Route::post('/{trip}/calls/schedule', [CallController::class, 'scheduleWithMeet']);
    });

    // ── [Itinerary Routes] ──
    Route::prefix('itinerary')->group(function () {
        Route::get('/', [ItineraryController::class, 'index']);
        Route::post('/', [ItineraryController::class, 'store']);
        Route::get('/{itinerary}', [ItineraryController::class, 'show']);
        Route::put('/{itinerary}', [ItineraryController::class, 'update']);
        Route::delete('/{itinerary}', [ItineraryController::class, 'destroy']);

        // ── [Itinerary Day Routes] ──
        Route::post('/{itinerary}/days', [ItineraryController::class, 'addDay']);
        Route::put('/days/{itineraryDay}', [ItineraryController::class, 'updateDay']);
        Route::delete('/days/{itineraryDay}', [ItineraryController::class, 'removeDay']);
        Route::post('/days/{itineraryDay}/destinations', [ItineraryController::class, 'addDestinationToDay']);
        Route::delete('/days/{itineraryDay}/destinations/{destinationId}', [ItineraryController::class, 'removeDestinationFromDay']);

        // ── [Itinerary Flight Routes] ──
        Route::get('/{itinerary}/flights/search', [ItineraryController::class, 'searchFlights']);
        Route::post('/{itinerary}/flights', [ItineraryController::class, 'addFlight']);
        Route::put('/flights/{itineraryFlight}', [ItineraryController::class, 'updateFlight']);
        Route::delete('/flights/{itineraryFlight}', [ItineraryController::class, 'removeFlight']);

        // ── [Itinerary Accommodation Routes] ──
        Route::get('/{itinerary}/accommodation/search', [ItineraryController::class, 'searchHotels']);
        Route::post('/{itinerary}/accommodation', [ItineraryController::class, 'addAccommodation']);
        Route::put('/accommodation/{itineraryAccommodation}', [ItineraryController::class, 'updateAccommodation']);
        Route::delete('/accommodation/{itineraryAccommodation}', [ItineraryController::class, 'removeAccommodation']);
    });

    // ── [Call Routes] ──
    Route::prefix('calls')->group(function () {
        Route::get('/', [CallController::class, 'index']);
        Route::post('/', [CallController::class, 'store']);
        Route::get('/{call}', [CallController::class, 'show']);
        Route::put('/{call}', [CallController::class, 'update']);
        Route::delete('/{call}', [CallController::class, 'destroy']);
        Route::post('/{call}/end', [CallController::class, 'endCall']);

        // ── [Call Action Item Routes] ──
        Route::post('/{call}/action-items', [CallController::class, 'addActionItem']);
        Route::put('/action-items/{callActionItem}', [CallController::class, 'updateActionItem']);
        Route::delete('/action-items/{callActionItem}', [CallController::class, 'removeActionItem']);
    });

    // ── [Gmail Routes] ── Connect/status/disconnect for Settings > Channels. The public
    // OAuth callback counterpart is registered above, outside this auth:sanctum group.
    Route::prefix('gmail')->group(function () {
        Route::get('/connect', [GmailController::class, 'connect']);
        Route::get('/status', [GmailController::class, 'status']);
        Route::post('/disconnect', [GmailController::class, 'disconnect']);
        Route::patch('/meet-tracking', [GmailController::class, 'updateMeetTracking']);
    });

    // ── [Conversation Routes] ── Synced inbox (currently Gmail-only) + the "link this
    // conversation to a trip" triage action used by the Messages page, plus sending real
    // replies and asking meridian-ai for draft suggestions.
    Route::prefix('conversations')->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::get('/{conversation}', [ConversationController::class, 'show']);
        Route::patch('/{conversation}', [ConversationController::class, 'update']);
        Route::delete('/{conversation}', [ConversationController::class, 'destroy']);
        Route::post('/{conversation}/messages', [ConversationController::class, 'sendMessage']);
        Route::post('/{conversation}/suggest-reply', [ConversationController::class, 'suggestReply']);
        Route::post('/{conversation}/request-travel-details', [ConversationController::class, 'requestTravelDetails']);
        Route::post('/{conversation}/extract-trip-details', [ConversationController::class, 'extractTripDetails']);
    });

    // ── [Gmail Thread Routes] ── Browse a connected mailbox and opt specific threads into
    // tracking — see GmailThreadController's docblock for why this is opt-in, not automatic.
    Route::prefix('gmail/threads')->group(function () {
        Route::get('/browse', [GmailThreadController::class, 'browse']);
        Route::post('/', [GmailThreadController::class, 'store']);
    });

    // ── [Company Routes] ──
    Route::apiResource('companies', CompanyController::class)->only(['index', 'show', 'update']);

    // ── [User Routes] ──
    Route::apiResource('users', UserController::class);
    Route::prefix('users')->group(function() {
        Route::put('/{user}/status', [UserController::class, 'updateStatus']);
        Route::put('/{user}/role', [UserController::class, 'updateRole']);
    });

    // ── [Invitation Routes] ──
    Route::post('/invitations', [InvitationController::class, 'store']);

    // ── [Demo Invite Routes] ── Any authenticated user can invite someone to a seat on the
    // shared demo account (see DemoInviteController docblock) — accept/show above are public.
    Route::post('/demo-invites', [DemoInviteController::class, 'store']);

    // ── [Profile Routes] ──
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

    // ── [Transaction Routes] ──
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/{transaction}', [TransactionController::class, 'show']);
        Route::post('/subscription', [TransactionController::class, 'recordSubscriptionPayment']);
        Route::post('/trip', [TransactionController::class, 'recordTripPayment']);
        Route::put('/{transaction}/status', [TransactionController::class, 'updateStatus']);
    });

    // ── [Subscription Tier Routes] ── Read-only plan catalog for the Pricing page.
    Route::apiResource('subscription-tiers', SubscriptionTierController::class)->only(['index', 'show']);

    // ── [Company Subscription Routes] ── A company's own subscription history + "subscribe".
    Route::apiResource('company-subscriptions', CompanySubscriptionController::class)->only(['index', 'show', 'store']);

    // ── [Moolre Payment Routes] ── Hosted-checkout mobile-money payments (see
    // MoolrePaymentController docblock). The public webhook counterpart is registered above,
    // outside this auth:sanctum group.
    Route::prefix('payments/moolre')->group(function () {
        Route::post('/trip', [MoolrePaymentController::class, 'initiateTripPayment']);
        Route::post('/subscription', [MoolrePaymentController::class, 'initiateSubscriptionPayment']);
        Route::get('/{transaction}/status', [MoolrePaymentController::class, 'status']);
    });

    // ── [WeWire Routes] ── Business onboarding (sub-customer + KYC), up-to-3 multi-currency
    // virtual accounts, beneficiaries, the reconciliation queue, and per-trip payment plans.
    // See WeWireOnboardingController/WeWireAccountController/WeWireBeneficiaryController/
    // PaymentPlanController/WeWirePaymentController docblocks. The public collection page and
    // webhook counterparts are registered above, outside this auth:sanctum group.
    Route::prefix('wewire')->group(function () {
        Route::post('/subcustomer', [WeWireOnboardingController::class, 'registerSubCustomer']);
        Route::post('/subcustomer/kyc', [WeWireOnboardingController::class, 'submitKyc']);
        Route::get('/subcustomer', [WeWireOnboardingController::class, 'status']);
        Route::apiResource('accounts', WeWireAccountController::class)->only(['index', 'store', 'update']);
        Route::apiResource('beneficiaries', WeWireBeneficiaryController::class)->only(['index', 'store']);
        Route::get('/inbound', [WeWirePaymentController::class, 'listInbound']);
        Route::post('/inbound/{inbound}/match', [WeWirePaymentController::class, 'matchInbound']);
        Route::get('/disbursements', [WeWirePaymentController::class, 'listDisbursements']);
        Route::post('/disbursements/{disbursement}/retry', [WeWirePaymentController::class, 'retryDisbursement']);
        // ── [Agency Payouts] ── Dashboard "pay out agency for this trip" panel — see
        // WeWirePaymentController::tripBalances/payoutTrip.
        Route::get('/trip-balances', [WeWirePaymentController::class, 'tripBalances']);
    });
    Route::post('/trips/{trip}/payment-plan', [PaymentPlanController::class, 'store']);
    Route::get('/trips/{trip}/payment-plan', [PaymentPlanController::class, 'show']);
    Route::patch('/payment-plans/{plan}/reference', [PaymentPlanController::class, 'updateReference']);
    Route::post('/trips/{trip}/payout', [WeWirePaymentController::class, 'payoutTrip']);

    // ── [Paystack Payment Routes] ── Hosted-checkout tour operator subscription payments (see
    // PaystackPaymentController docblock). The public webhook counterpart is registered above,
    // outside this auth:sanctum group.
    Route::prefix('payments/paystack')->group(function () {
        Route::post('/subscription', [PaystackPaymentController::class, 'initiateSubscriptionPayment']);
        Route::get('/{transaction}/status', [PaystackPaymentController::class, 'status']);
    });
});
