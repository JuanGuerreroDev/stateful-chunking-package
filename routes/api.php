<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\Controllers\ChunkUploadController;

$prefix = config('stateful-chunking.routes.prefix', 'api/chunks');
$middleware = config('stateful-chunking.routes.middleware', ['api']);

$rateLimitsEnabled = (bool) config('stateful-chunking.rate_limits.enabled', true);

Route::group([
    'prefix' => $prefix,
    'middleware' => $middleware,
], function () use ($rateLimitsEnabled) {
    Route::post('/initiate', [ChunkUploadController::class, 'initiate'])
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-initiate'] : []);

    Route::post('/upload', [ChunkUploadController::class, 'upload'])
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-upload'] : []);

    Route::get('/status/{sessionId}', [ChunkUploadController::class, 'status'])
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-status'] : []);

    Route::post('/complete', [ChunkUploadController::class, 'complete'])
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-complete'] : []);

    Route::delete('/cancel/{sessionId}', [ChunkUploadController::class, 'cancel'])
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-cancel'] : []);
});
