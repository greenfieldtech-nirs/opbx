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
    | Auto Dialer Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Go-based auto dialer worker that executes
    | outbound call campaigns via the Cloudonix platform.
    |
    */
    'dialer_worker' => [
        // Authentication token for worker API access
        'token' => env('DIALER_WORKER_API_TOKEN'),

        // Token rotation for zero-downtime deployments
        'token_secondary' => env('DIALER_WORKER_API_TOKEN_SECONDARY'),

        // Retry configuration
        'max_retries' => env('DIALER_WORKER_MAX_RETRIES', 3),
        'retry_delay_seconds' => env('DIALER_WORKER_RETRY_DELAY', 60),

        // Rate limiting
        'rate_limit_per_minute' => env('DIALER_WORKER_RATE_LIMIT', 1000),
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

    /*
    |--------------------------------------------------------------------------
    | Google reCAPTCHA v3
    |--------------------------------------------------------------------------
    |
    | Configuration for Google reCAPTCHA v3 to prevent bot registrations.
    | reCAPTCHA v3 is invisible to users and returns a score (0.0 to 1.0).
    |
    | Get your keys from: https://www.google.com/recaptcha/admin
    |
    */
    'recaptcha' => [
        'enabled' => env('RECAPTCHA_ENABLED', false),
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'min_score' => env('RECAPTCHA_MIN_SCORE', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactional Email Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for transactional email providers. ONLY ONE provider
    | can be enabled at a time. If multiple providers are enabled, the
    | application will display an error and refuse to send emails.
    |
    | Supported providers: mailgun, mailjet, mailerlite, sendinblue
    |
    */
    'transactional_email' => [
        // The active provider (must match a provider key below)
        'provider' => env('EMAIL_PROVIDER', 'mailgun'),

        // Queue configuration
        'queue' => env('EMAIL_QUEUE', 'default'),

        // Tracking settings
        'track_opens' => env('EMAIL_TRACK_OPENS', true),
        'track_clicks' => env('EMAIL_TRACK_CLICKS', true),

        // Provider configurations (ONLY ONE should be enabled)
        'providers' => [
            'mailgun' => [
                'enabled' => env('MAILGUN_ENABLED', false),
                'domain' => env('MAILGUN_DOMAIN'),
                'secret' => env('MAILGUN_SECRET'),
                'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
                'region' => env('MAILGUN_REGION', 'us'), // us or eu
            ],
            'mailjet' => [
                'enabled' => env('MAILJET_ENABLED', false),
                'key' => env('MAILJET_APIKEY'),
                'secret' => env('MAILJET_APISECRET'),
            ],
            'mailerlite' => [
                'enabled' => env('MAILERLITE_ENABLED', false),
                'api_key' => env('MAILERLITE_API_KEY'),
            ],
            'sendinblue' => [
                'enabled' => env('SENDINBLUE_ENABLED', false),
                'api_key' => env('SENDINBLUE_API_KEY'),
            ],
        ],
    ],

];
