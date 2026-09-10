<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Juanoecr\StatefulChunking\Console\Commands\ClearStaleSessionsCommand;
use Juanoecr\StatefulChunking\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Http\CallerIdentity;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Repositories\CacheStateRepository;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Storage\LocalStorageAdapter;

final class StatefulChunkingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/stateful-chunking.php',
            'stateful-chunking'
        );

        $this->app->bind(StateRepositoryInterface::class, CacheStateRepository::class);
        $this->app->bind(FileStorageInterface::class, LocalStorageAdapter::class);

        $this->app->singleton(
            StatefulChunkingService::class,
            fn () => new StatefulChunkingService
        );
        $this->app->alias(
            StatefulChunkingService::class,
            'stateful-chunking.service'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/stateful-chunking.php' => config_path('stateful-chunking.php'),
            ], 'stateful-chunking-config');

            $this->commands([
                ClearStaleSessionsCommand::class,
            ]);
        }

        if (config('stateful-chunking.routes.enabled', true)) {
            $this->configureRateLimiting();
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        }
    }

    /**
     * Configure rate limiting for the chunking API endpoints.
     */
    protected function configureRateLimiting(): void
    {
        if (! config('stateful-chunking.rate_limits.enabled', true)) {
            return;
        }

        // One answer to "who is calling?", shared with the controller's ownership check.
        // This used to be a second, independent implementation built on
        // property_exists($user, 'id'), which is always false for an Eloquent model —
        // so authenticated callers were silently bucketed by IP.
        $resolveKey = static fn (Request $request): string => CallerIdentity::resolve($request);

        $getConfigLimit = function (string $key, int $default): int {
            $val = config("stateful-chunking.rate_limits.{$key}", $default);

            return is_numeric($val) ? (int) $val : $default;
        };

        RateLimiter::for('stateful-chunking-initiate', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('initiate', 10))
                ->by($resolveKey($request));
        });

        RateLimiter::for('stateful-chunking-upload', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('upload', 120))
                ->by($resolveKey($request));
        });

        RateLimiter::for('stateful-chunking-status', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('status', 60))
                ->by($resolveKey($request));
        });

        RateLimiter::for('stateful-chunking-complete', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('complete', 20))
                ->by($resolveKey($request));
        });

        RateLimiter::for('stateful-chunking-cancel', function (Request $request) use ($resolveKey, $getConfigLimit) {
            return Limit::perMinute($getConfigLimit('cancel', 20))
                ->by($resolveKey($request));
        });
    }
}
