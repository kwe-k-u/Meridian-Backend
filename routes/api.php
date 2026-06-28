<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CallController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\ItineraryController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function() {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register-company', [AuthController::class, 'registerCompany']);
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink']);
    Route::post('/reset-password', [AuthController::class, 'resetForgotPassword']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    // Destinations
    Route::apiResource('destinations', DestinationController::class);

    // Customers
    Route::apiResource('customers', CustomerController::class);

    // Trips (was projects)
    Route::prefix('trips')->group(function () {
        Route::get('/', [TripController::class, 'index']);
        Route::get('/status/{status?}', [TripController::class, 'index']);
        Route::post('/', [TripController::class, 'store']);
        Route::get('/{trip}', [TripController::class, 'show']);
        Route::put('/{trip}', [TripController::class, 'update']);
        Route::delete('/{trip}', [TripController::class, 'destroy']);
        Route::post('/{trip}/customers', [TripController::class, 'addCustomer']);
        Route::delete('/{trip}/customers/{customer}', [TripController::class, 'removeCustomer']);
    });

    // Itineraries (was trips)
    Route::prefix('itinerary')->group(function () {
        Route::get('/', [ItineraryController::class, 'index']);
        Route::post('/', [ItineraryController::class, 'store']);
        Route::get('/{itinerary}', [ItineraryController::class, 'show']);
        Route::put('/{itinerary}', [ItineraryController::class, 'update']);
        Route::delete('/{itinerary}', [ItineraryController::class, 'destroy']);

        // Itinerary Days
        Route::post('/{itinerary}/days', [ItineraryController::class, 'addDay']);
        Route::put('/days/{itineraryDay}', [ItineraryController::class, 'updateDay']);
        Route::delete('/days/{itineraryDay}', [ItineraryController::class, 'removeDay']);
        Route::post('/days/{itineraryDay}/destinations', [ItineraryController::class, 'addDestinationToDay']);
        Route::delete('/days/{itineraryDay}/destinations/{destinationId}', [ItineraryController::class, 'removeDestinationFromDay']);

        // Itinerary Flights
        Route::post('/{itinerary}/flights', [ItineraryController::class, 'addFlight']);
        Route::put('/flights/{itineraryFlight}', [ItineraryController::class, 'updateFlight']);
        Route::delete('/flights/{itineraryFlight}', [ItineraryController::class, 'removeFlight']);

        // Itinerary Accommodation
        Route::post('/{itinerary}/accommodation', [ItineraryController::class, 'addAccommodation']);
        Route::put('/accommodation/{itineraryAccommodation}', [ItineraryController::class, 'updateAccommodation']);
        Route::delete('/accommodation/{itineraryAccommodation}', [ItineraryController::class, 'removeAccommodation']);
    });

    // Calls
    Route::prefix('calls')->group(function () {
        Route::get('/', [CallController::class, 'index']);
        Route::post('/', [CallController::class, 'store']);
        Route::get('/{call}', [CallController::class, 'show']);
        Route::put('/{call}', [CallController::class, 'update']);
        Route::delete('/{call}', [CallController::class, 'destroy']);
        Route::post('/{call}/end', [CallController::class, 'endCall']);

        // Action Items
        Route::post('/{call}/action-items', [CallController::class, 'addActionItem']);
        Route::put('/action-items/{callActionItem}', [CallController::class, 'updateActionItem']);
        Route::delete('/action-items/{callActionItem}', [CallController::class, 'removeActionItem']);
    });

    // Transactions
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/{transaction}', [TransactionController::class, 'show']);
        Route::post('/subscription', [TransactionController::class, 'recordSubscriptionPayment']);
        Route::post('/trip', [TransactionController::class, 'recordTripPayment']);
        Route::put('/{transaction}/status', [TransactionController::class, 'updateStatus']);
    });
});
