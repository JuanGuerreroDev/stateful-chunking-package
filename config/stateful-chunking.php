<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Define the prefix, middlewares, and auto-registration behavior for the
    | chunking API endpoints.
    |
    */
    'routes' => [
        'enabled' => env('STATEFUL_CHUNKING_ROUTES_ENABLED', true),
        'prefix' => env('STATEFUL_CHUNKING_ROUTE_PREFIX', 'api/chunks'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting & Throttling
    |--------------------------------------------------------------------------
    |
    | Differentiated rate limits per endpoint operation (requests per minute).
    | Protects against Denial of Service (DoS) and memory exhaustion.
    |
    */
    'rate_limits' => [
        'enabled' => (bool) env('STATEFUL_CHUNKING_RATE_LIMIT_ENABLED', true),
        'initiate' => (int) env('STATEFUL_CHUNKING_RATE_INITIATE', 10),
        'upload' => (int) env('STATEFUL_CHUNKING_RATE_UPLOAD', 120),
        'status' => (int) env('STATEFUL_CHUNKING_RATE_STATUS', 60),
        'complete' => (int) env('STATEFUL_CHUNKING_RATE_COMPLETE', 20),
        'cancel' => (int) env('STATEFUL_CHUNKING_RATE_CANCEL', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stateful Parameters
    |--------------------------------------------------------------------------
    |
    | Default chunk size (must be a multiple of 256 KB = 262144 bytes).
    | Default is 2 MB (2,097,152 bytes) for PHP CLI compatibility.
    | Session TTL default is 21600 seconds (6 hours).
    |
    */
    'chunk_size_bytes' => (int) env('STATEFUL_CHUNKING_SIZE_BYTES', 2097152),
    'max_file_size_bytes' => (int) env('STATEFUL_CHUNKING_MAX_FILE_SIZE_BYTES', 10737418240), // 10 GB
    'max_total_chunks' => (int) env('STATEFUL_CHUNKING_MAX_TOTAL_CHUNKS', 10000),
    'forbidden_extensions' => [
        'php', 'phar', 'phtml', 'pht', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'inc', 'hphp', 'ctp',
        'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb', 'vbs', 'vbe', 'ps1',
        'asp', 'aspx', 'cer', 'asa', 'asax', 'cfm', 'cfc', 'jsp', 'jspx', 'shtml', 'shtm',
        'htaccess', 'htpasswd', 'user.ini',
    ],
    'allowed_extensions' => null,
    'session_ttl' => (int) env('STATEFUL_CHUNKING_SESSION_TTL', 21600),
    'max_chunk_retries' => (int) env('STATEFUL_CHUNKING_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Infrastructure Connections & Cache Persistence
    |--------------------------------------------------------------------------
    |
    | State persistence store. Leverages Laravel's unified Cache system across
    | all drivers (Redis, Memcached, Database, File, DynamoDB, Array).
    | When null, defaults to Laravel's configured default cache store.
    | Specify the storage disk to use for temporary staging and final assembled files.
    |
    */
    'cache_store' => env('STATEFUL_CHUNKING_CACHE_STORE'),
    'driver' => env('STATEFUL_CHUNKING_DRIVER'),
    'storage_disk' => env('STATEFUL_CHUNKING_STORAGE_DISK', 'local'),
    'storage_path' => env('STATEFUL_CHUNKING_STORAGE_PATH', 'uploads'),
    'expose_server_paths' => (bool) env('STATEFUL_CHUNKING_EXPOSE_SERVER_PATHS', false),
    'require_auth' => (bool) env('STATEFUL_CHUNKING_REQUIRE_AUTH', false),
    'log_channel' => env('STATEFUL_CHUNKING_LOG_CHANNEL'),
];

