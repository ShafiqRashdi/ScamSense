<?php

declare(strict_types=1);

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

    /*
    |--------------------------------------------------------------------------
    | ScamSense services
    |--------------------------------------------------------------------------
    |
    | AGENTS.md §5 forbids calling env() outside config files, so every value
    | the detection stack needs is resolved here once and read through config()
    | everywhere else. That also keeps `php artisan config:cache` safe — a
    | cached config makes env() return null at runtime, which is the classic
    | way a Laravel app breaks only in production.
    |
    | Timeouts come from AGENTS.md §2 rule 4: the ML hop gets 2s, external
    | providers 3s, each with a single retry, and a failure degrades the verdict
    | rather than throwing.
    |
    */

    'ml_service' => [
        'url' => env('ML_SERVICE_URL', 'http://127.0.0.1:5001'),
        'secret' => env('ML_SERVICE_SECRET'),
        'timeout' => 2,
        'retries' => 1,
        'retry_delay_ms' => 200,
        // Flask rejects a signed request older than this many seconds.
        'signature_ttl' => 60,
    ],

    'safe_browsing' => [
        'key' => env('GOOGLE_SAFE_BROWSING_KEY'),
        'timeout' => 3,
        'retries' => 1,
    ],

    'virustotal' => [
        'key' => env('VIRUSTOTAL_KEY'),
        'timeout' => 3,
        'retries' => 1,
    ],

    /*
     | Threat-intel responses are cached by URL hash. The free VirusTotal tier
     | allows four requests a minute, which a live demo would exhaust in
     | seconds without this.
     */
    'threat_intel' => [
        'cache_ttl' => 60 * 60 * 24,
    ],

];
