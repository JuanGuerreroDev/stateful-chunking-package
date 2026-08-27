<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Http\Controllers\ChunkUploadController;

$prefix = config('stateful-chunking.routes.prefix', 'api/chunks');
$middleware = config('stateful-chunking.routes.middleware', ['api']);

Route::group([
    'prefix' => $prefix,
    'middleware' => $middleware,
], function () {
    Route::post('/initiate', [ChunkUploadController::class, 'initiate']);
    Route::post('/upload', [ChunkUploadController::class, 'upload']);
    Route::get('/status/{sessionId}', [ChunkUploadController::class, 'status']);
    Route::post('/complete', [ChunkUploadController::class, 'complete']);
    Route::delete('/cancel/{sessionId}', [ChunkUploadController::class, 'cancel']);
});
