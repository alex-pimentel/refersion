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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'refersion' => [
        'client_id' => env('REFERSION_CLIENT_ID'),
        'client_secret' => env('REFERSION_CLIENT_SECRET'),
        'auth_url' => env('REFERSION_AUTH_URL', 'https://auth.refersion.com/oauth/token'),
        'base_url' => env('REFERSION_BASE_URL', 'https://api.refersion.com/v2026-08'),
        'token_type' => env('REFERSION_TOKEN_TYPE', 'short_lived'),
        'page_limit' => (int) env('REFERSION_PAGE_LIMIT', 100),
        'custom_fields_chunk' => (int) env('REFERSION_CUSTOM_FIELDS_CHUNK', 10),
        'leaderboard_size' => (int) env('REFERSION_LEADERBOARD_SIZE', 5),
        'leaderboard_cache_ttl' => (int) env('REFERSION_LEADERBOARD_CACHE_TTL', 300),
        'commission_unit' => env('REFERSION_COMMISSION_UNIT', 'cents'),
        'max_pages' => (int) env('REFERSION_MAX_PAGES', 500),
        'max_candidates' => (int) env('REFERSION_MAX_CANDIDATES', 1000),
        'connect_timeout' => (int) env('REFERSION_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('REFERSION_TIMEOUT', 15),
    ],

];
