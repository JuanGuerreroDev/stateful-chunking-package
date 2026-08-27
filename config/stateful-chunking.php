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
    | Stateful Parameters
    |--------------------------------------------------------------------------
    |
    | Default chunk size (must be a multiple of 256 KB = 262144 bytes).
    | Default is 2 MB (2,097,152 bytes) for PHP CLI compatibility.
    | Redis session TTL default is 21600 seconds (6 hours).
    |
    */
    'chunk_size_bytes' => (int) env('STATEFUL_CHUNKING_SIZE_BYTES', 2097152),
    'max_file_size_bytes' => (int) env('STATEFUL_CHUNKING_MAX_FILE_SIZE_BYTES', 10737418240), // 10 GB
    'redis_session_ttl' => (int) env('STATEFUL_CHUNKING_REDIS_TTL', 21600),
    'max_chunk_retries' => (int) env('STATEFUL_CHUNKING_MAX_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | Infrastructure Connections
    |--------------------------------------------------------------------------
    |
    | State driver ("cache" or "redis"). Default uses Laravel 12's configured
    | default cache store (config('cache.default')).
    | Specify the storage disk to use for session state and final assembled files.
    |
    */
    'driver' => env('STATEFUL_CHUNKING_DRIVER', config('cache.default', 'file')),
    'cache_store' => env('STATEFUL_CHUNKING_CACHE_STORE'),
    'redis_connection' => env('STATEFUL_CHUNKING_REDIS_CONNECTION', 'default'),
    'storage_disk' => env('STATEFUL_CHUNKING_STORAGE_DISK', 'local'),
    'storage_path' => env('STATEFUL_CHUNKING_STORAGE_PATH', 'uploads'),
];

