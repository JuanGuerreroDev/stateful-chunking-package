<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Juanoecr\StatefulChunkingUpload\Console\Commands\ClearStaleSessionsCommand;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunkingUpload\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Events\ChunkSessionExpired;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\Contracts\ResolvesCallerIdentity;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Http\RequestCallerIdentity;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Listeners\PurgeExpiredSessionChunks;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Repositories\CacheStateRepository;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Storage\LocalStorageAdapter;

final class StatefulChunkingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/stateful-chunking-upload.php',
            'stateful-chunking-upload'
        );

        $this->app->bind(StateRepositoryInterface::class, CacheStateRepository::class);
        $this->app->bind(FileStorageInterface::class, LocalStorageAdapter::class);
        // Identity is an extension point: a multi-tenant or API-key deployment rebinds
        // this and both the ownership check and the rate-limit bucket follow.
        $this->app->bind(ResolvesCallerIdentity::class, RequestCallerIdentity::class);

        $this->app->singleton(
            StatefulChunkingService::class,
            fn () => new StatefulChunkingService
        );
        $this->app->alias(
            StatefulChunkingService::class,
            'stateful-chunking-upload.service'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Expiry is detected lazily, on read, by whichever request happens to touch a
        // dead session. Wiring the purge here means that request also frees the disk,
        // instead of leaving it for a sweep that may be hours away or unscheduled.
        Event::listen(ChunkSessionExpired::class, PurgeExpiredSessionChunks::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/stateful-chunking-upload.php' => config_path('stateful-chunking-upload.php'),
            ], 'stateful-chunking-upload-config');

            $this->commands([
                ClearStaleSessionsCommand::class,
            ]);
        }

        if (config('stateful-chunking-upload.routes.enabled', true)) {
            $this->configureRateLimiting();
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        }
    }

    /**
     * Configure rate limiting for the chunking API endpoints.
     */
    protected function configureRateLimiting(): void
    {
        if (! config('stateful-chunking-upload.rate_limits.enabled', true)) {
            return;
        }

        // Resolved from the container per request, not captured at boot, so a consumer
        // that rebinds ResolvesCallerIdentity changes the rate-limit buckets too — not
        // only session ownership. One answer to "who is calling?", for both readers.
        $resolveKey = static fn (Request $request): string => app(ResolvesCallerIdentity::class)->resolve($request);

        $getConfigLimit = function (string $key, int $default): int {
            $val = config("stateful-chunking-upload.rate_limits.{$key}", $default);

            return is_numeric($val) ? (int) $val : $default;
        };

        RateLimiter::for('stateful-chunking-upload.initiate', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('initiate', 10))
                ->by($resolveKey($request));
        });

        RateLimiter::for('stateful-chunking-upload.upload', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('upload', 120))
                ->by($resolveKey($request));
        });

        RateLimiter::for('stateful-chunking-upload.status', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('status', 60))
                ->by($resolveKey($request));
        });

        RateLimiter::for('stateful-chunking-upload.complete', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('complete', 20))
                ->by($resolveKey($request));
        });

        RateLimiter::for('stateful-chunking-upload.cancel', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('cancel', 20))
                ->by($resolveKey($request));
        });
    }
}
