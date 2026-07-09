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

    // Moolre (https://docs.moolre.com/) — mobile-money hosted-checkout payments.
    // Sandbox only requires api_user; live also needs api_key (payment init) and
    // api_pubkey (status checks). See App\Services\MoolreService.
    'moolre' => [
        'base_url' => env('MOOLRE_BASE_URL', 'https://sandbox.moolre.com'),
        'api_user' => env('MOOLRE_API_USER'),
        'api_key' => env('MOOLRE_PRIVATE_KEY'),
        'api_pubkey' => env('MOOLRE_PUBLIC_KEY'),
        'account_number' => env('MOOLRE_ACCOUNT_NUMBER'),
        'callback_url' => env('MOOLRE_CALLBACK_URL'),
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

];
