<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | WebSocket Server Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file is used when using beyondcode/laravel-websockets
    | package. For Soketi (recommended), this file is not required but kept
    | for reference and future compatibility.
    |
    */

    'dashboard' => [
        'port' => env('LARAVEL_WEBSOCKETS_PORT', 6001),
    ],

    'apps' => [
        [
            'id' => env('PUSHER_APP_ID', 'app-id'),
            'name' => env('APP_NAME', 'OPBX'),
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'enable_client_messages' => false,
            'enable_statistics' => true,
        ],
    ],

    'ssl' => [
        'local_cert' => env('LARAVEL_WEBSOCKETS_SSL_LOCAL_CERT', null),
        'local_pk' => env('LARAVEL_WEBSOCKETS_SSL_LOCAL_PK', null),
        'passphrase' => env('LARAVEL_WEBSOCKETS_SSL_PASSPHRASE', null),
    ],

    'max_request_size_in_kb' => 250,

    'statistics' => [
        'model' => \BeyondCode\LaravelWebSockets\Statistics\Models\WebSocketsStatisticsEntry::class,
        'logger' => \BeyondCode\LaravelWebSockets\Statistics\Logger\HttpStatisticsLogger::class,
        'interval_in_seconds' => 60,
        'delete_statistics_older_than_days' => 60,
    ],

    'replication' => [
        'mode' => env('WEBSOCKETS_REPLICATION_MODE', 'none'),
        'modes' => [
            'local' => \BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManagers\LocalChannelManager::class,
            'none' => \BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManagers\LocalChannelManager::class,
        ],
    ],

];
