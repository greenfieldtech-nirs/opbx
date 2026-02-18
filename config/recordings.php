<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Recordings Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Recordings feature including security settings,
    | file handling, and remote URL validation.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    */
    'max_file_size_kb' => env('RECORDINGS_MAX_SIZE_KB', 5120), // 5MB default

    'allowed_mime_types' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave'],

    'allowed_extensions' => ['mp3', 'wav'],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    */
    'storage_disk' => env('RECORDINGS_STORAGE_DISK', 'recordings'),

    'local_path' => env('RECORDINGS_LOCAL_PATH', 'storage/app/recordings'),

    /*
    |--------------------------------------------------------------------------
    | Remote URL Settings
    |--------------------------------------------------------------------------
    */
    'allow_http' => env('RECORDINGS_ALLOW_HTTP', true),

    'url_timeout' => env('RECORDINGS_URL_TIMEOUT', 10), // seconds

    'allowed_domains' => env('RECORDINGS_ALLOWED_DOMAINS')
        ? explode(',', env('RECORDINGS_ALLOWED_DOMAINS'))
        : [], // Empty array means allow all domains

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */
    'access_token_expiry' => env('RECORDINGS_ACCESS_TOKEN_EXPIRY', 30), // minutes

    'enable_secure_delete' => env('RECORDINGS_SECURE_DELETE', true),

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */
    'queue_processing_enabled' => env('RECORDINGS_QUEUE_PROCESSING', true),

    'queue_connection' => env('RECORDINGS_QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Audit & Compliance
    |--------------------------------------------------------------------------
    */
    'enable_audit_logging' => env('RECORDINGS_AUDIT_LOGGING', true),

    'retention_days' => env('RECORDINGS_RETENTION_DAYS', 365), // Days to keep recordings

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    */
    'cache_enabled' => env('RECORDINGS_CACHE_ENABLED', true),

    'cache_ttl' => env('RECORDINGS_CACHE_TTL', 3600), // 1 hour
];