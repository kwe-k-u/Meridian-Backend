<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // The seeded shared demo account (see database/seeders/DemoDataSeeder.php) that
    // demo invitees get a seat on. Looked up by email at runtime rather than a fixed ID,
    // since IdGeneratorService::generateId() assigns the company_id randomly at seed time.
    'demo' => [
        'account_email' => env('DEMO_ACCOUNT_EMAIL', 'demo@meridian.com'),
        'invite_expiry_days' => env('DEMO_INVITE_EXPIRY_DAYS', 30),
    ],

    // WeWire (https://docs.wewire.com/) — multi-currency virtual accounts, payouts/disbursement,
    // and business KYC. No hosted checkout link product — customer collection happens via bank
    // transfer/mobile money into a virtual account, reconciled by reference code (see
    // App\Services\WeWireService, App\Http\Controllers\WeWirePaymentController).
    'wewire' => [
        'base_url' => env('WEWIRE_BASE_URL', 'https://stage-capi.wewireafrica.com'),
        'api_key' => env('WEWIRE_API_KEY'),
        'webhook_secret' => env('WEWIRE_WEBHOOK_SECRET'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
        // Defaults on: WeWire's KYC and beneficiary-creation endpoints are currently broken on
        // their end, so App\Services\WeWireService fakes those specific calls (only those —
        // virtual accounts and payouts still hit the real API) until that's fixed. Set
        // WEWIRE_SIMULATE=false once WeWire confirms KYC/beneficiary creation works again.
        'simulate' => env('WEWIRE_SIMULATE', true),
        // Gates WeWirePaymentController::simulatePublicPayment's "Proceed with payment"
        // fallback — separate from `simulate` above because it protects a different thing:
        // stopping a real customer from fabricating a real payment. That risk doesn't exist
        // while every account still lives on WeWire's sandbox (stage-capi.wewireafrica.com)
        // regardless of `simulate`, so this defaults on independently and should only be
        // turned off once real bank transfers into a production WeWire account are possible.
        'allow_simulated_payments' => env('WEWIRE_ALLOW_SIMULATED_PAYMENTS', true),
    ],

    // Paystack (https://paystack.com/docs/) — hosted-checkout card/bank payments, currently
    // used for tour operator subscription payments only. See App\Services\PaystackService.
    'paystack' => [
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    // SerpApi (https://serpapi.com/) — real flight/hotel search for the itinerary builder.
    // Called server-side only (see App\Services\SerpApiService) so the key is never bundled
    // into frontend JS, where anyone could read it out and rack up charges on the account.
    'serpapi' => [
        'key' => env('SERPAPI_KEY'),
        'base_url' => 'https://serpapi.com/search.json',
    ],

    // meridian-ai — Python FastAPI microservice for LLM-powered itinerary generation.
    // Service-to-service auth: both sides share MERIDIAN_AI_SERVICE_TOKEN (Bearer).
    // See App\Services\AI\MeridianAiService.
    'meridian_ai' => [
        'url'     => env('MERIDIAN_AI_URL', 'http://127.0.0.1:9000'),
        'token'   => env('MERIDIAN_AI_SERVICE_TOKEN'),
        'timeout' => env('MERIDIAN_AI_TIMEOUT', 90),
    ],

    // Ticketmaster Discovery API v2 — real event search passed to the AI as
    // event_candidates so generated itineraries reference bookable events.
    // See App\Services\TicketmasterService.
    'ticketmaster' => [
        'key'      => env('TICKETMASTER_API_KEY'),
        'base_url' => env('TICKETMASTER_BASE_URL', 'https://app.ticketmaster.com/discovery/v2'),
    ],

    // Booking.com via RapidAPI — richer hotel data (real pricing, stars, reviews)
    // used alongside SerpApi stays as stay_candidates for AI generation.
    // See App\Services\BookingComService.
    'hotels_rapidapi' => [
        'key'  => env('HOTELS_RAPIDAPI_KEY'),
        'host' => env('HOTELS_RAPIDAPI_HOST', 'booking-com15.p.rapidapi.com'),
    ],

    // Google OAuth (Gmail integration) — see App\Services\Gmail\GmailOAuthService and
    // App\Http\Controllers\GmailController. redirect_uri must exactly match an authorized
    // redirect URI configured on the OAuth 2.0 Client in Google Cloud Console.
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri'  => env('GOOGLE_REDIRECT_URI'),
    ],

];
