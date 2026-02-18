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

    'cloudonix' => [
        'api_token' => env('CLOUDONIX_API_TOKEN'),
        'api_base_url' => env('CLOUDONIX_API_BASE_URL', 'https://api.cloudonix.io'),
        'voice_webhook_token' => env('VOICE_WEBHOOK_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | UserCheck Email Validation Service
    |--------------------------------------------------------------------------
    |
    | Configuration for the UserCheck.com email validation API.
    | All validation rules are individually configurable via environment
    | variables to allow flexible security policies.
    |
    | IMPORTANT: This is a BLOCKING validation. If the API is unavailable,
    | registration will be blocked for security reasons.
    |
    */
    'usercheck' => [
        'enabled' => env('USERCHECK_ENABLED', true),
        'api_token' => env('USERCHECK_API_TOKEN'),
        'base_url' => env('USERCHECK_BASE_URL', 'https://api.usercheck.com'),
        'timeout' => env('USERCHECK_TIMEOUT', 5),

        // Individual validation rule toggles (all default to true for strict validation)
        'block_disposable' => env('USERCHECK_BLOCK_DISPOSABLE', true),
        'block_blocklisted' => env('USERCHECK_BLOCK_BLOCKLISTED', true),
        'block_spam' => env('USERCHECK_BLOCK_SPAM', true),
        'block_role_accounts' => env('USERCHECK_BLOCK_ROLE_ACCOUNTS', true),
        'block_relay_domains' => env('USERCHECK_BLOCK_RELAY_DOMAINS', false),
        'block_public_domains' => env('USERCHECK_BLOCK_PUBLIC_DOMAINS', false),
    ],

];
