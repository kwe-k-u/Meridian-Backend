<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySubscriptionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ItineraryController;
use App\Http\Controllers\MoolrePaymentController;
use App\Http\Controllers\SubscriptionTierController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\UserController;
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

// ── [Authenticated Routes] ──
Route::middleware('auth:sanctum')->group(function () {
    // ── [Dashboard] ──
    Route::get('/dashboard', DashboardController::class);

    // ── [Destination Routes] ──
    Route::apiResource('destinations', DestinationController::class);

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

    // ── [Company Routes] ──
    Route::apiResource('companies', CompanyController::class)->only(['index', 'show', 'update']);

    // ── [User Routes] ──
    Route::apiResource('users', UserController::class)->only(['index', 'show', 'update']);

    // ── [Invitation Routes] ──
    Route::post('/invitations', [InvitationController::class, 'store']);

    // ── [Profile Routes] ──
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
});
