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

   'telegram' => [
    'bot_token'          => env('TELEGRAM_BOT_TOKEN'),
    'chat_id'            => env('TELEGRAM_CHAT_ID'),
    // Financial Team dashboard — optional second Telegram destination.
    // When set, every notify() call (new/approved deposits and
    // withdrawals) also reaches this chat, in addition to the existing
    // chat_id above. Leaving it unset changes nothing about current
    // behavior — the primary chat_id keeps working exactly as before.
    'financial_chat_id'  => env('TELEGRAM_FINANCIAL_CHAT_ID'),
    'agent_name'     => env('TELEGRAM_AGENT_NAME'),
    'agent_username' => env('TELEGRAM_AGENT_USERNAME'),
],

    // Market/Trading Interface (Phase 3) — market DATA only, never
    // execution. Provider is swappable: everything provider-specific
    // lives in MarketDataService; the controller/frontend only know
    // about our own normalized shape. Defaults to CoinGecko's free
    // public tier (no key required) so the feature works out of the box;
    // set MARKET_DATA_API_KEY to move to a paid tier/different provider
    // later without touching any other code.
    'market_data' => [
        'provider'   => env('MARKET_DATA_PROVIDER', 'coinpaprika'),
        'base_url'   => env('MARKET_DATA_BASE_URL', 'https://api.coinpaprika.com/v1'),
        'api_key'    => env('MARKET_DATA_API_KEY'), // null on the free tier — never sent to the frontend either way
        'cache_ttl'  => (int) env('MARKET_DATA_CACHE_TTL', 60), // seconds
    ],

    // Live Market — Commodities & Indices (Gold, S&P 500, NASDAQ).
    // CoinPaprika only covers crypto, so these use Twelve Data's free
    // "Basic" plan (no card required — https://twelvedata.com/register).
    // Leave TWELVE_DATA_API_KEY unset and these assets show an honest
    // "not connected" state instead of a fabricated price; the rest of
    // Live Market (crypto) keeps working normally either way.
    'twelve_data' => [
        'api_key'  => env('TWELVE_DATA_API_KEY'),
        'base_url' => env('TWELVE_DATA_BASE_URL', 'https://api.twelvedata.com'),
    ],

    // Gaming & Prediction — optional automatic fixture source. The key
    // lives only here (server-side config, read from .env) and is never
    // included in any API response — see FootballDataService and
    // AdminFixtureController::fetchFromApi(), neither of which ever
    // returns this value to the frontend.
    'football_data' => [
        'api_key' => env('FOOTBALL_DATA_API_KEY'),
    ],
];
