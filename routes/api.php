<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Controllers\ChunkUploadController;

$prefix = config('stateful-chunking-upload.routes.prefix', 'api/chunks');
$middleware = config('stateful-chunking-upload.routes.middleware', ['api']);

$rateLimitsEnabled = (bool) config('stateful-chunking-upload.rate_limits.enabled', true);

// Route parameters carrying a session id are constrained to the exact UUID shape the
// SessionId value object accepts. A malformed identifier therefore never reaches the
// controller: it fails to match the route and Laravel answers 404, instead of arriving
// at the ownership check as a value nothing has validated.
$sessionIdPattern = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::group([
    'prefix' => $prefix,
    'middleware' => $middleware,
], function () use ($rateLimitsEnabled, $sessionIdPattern) {
    Route::post('/initiate', [ChunkUploadController::class, 'initiate'])
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-upload.initiate'] : []);

    Route::post('/upload', [ChunkUploadController::class, 'upload'])
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-upload.upload'] : []);

    Route::get('/status/{sessionId}', [ChunkUploadController::class, 'status'])
        ->where('sessionId', $sessionIdPattern)
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-upload.status'] : []);

    Route::post('/complete', [ChunkUploadController::class, 'complete'])
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-upload.complete'] : []);

    Route::delete('/cancel/{sessionId}', [ChunkUploadController::class, 'cancel'])
        ->where('sessionId', $sessionIdPattern)
        ->middleware($rateLimitsEnabled ? ['throttle:stateful-chunking-upload.cancel'] : []);
});
