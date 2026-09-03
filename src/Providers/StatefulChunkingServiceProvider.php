<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Providers;

use Illuminate\Support\ServiceProvider;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Core\Contracts\FileStorageInterface;
use StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Repositories\CacheStateRepository;
use StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Storage\LocalStorageAdapter;
use StatefulChunking\LaravelPackage\Console\Commands\ClearStaleSessionsCommand;

final class StatefulChunkingServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/stateful-chunking.php',
            'stateful-chunking'
        );

        $this->app->bind(StateRepositoryInterface::class, CacheStateRepository::class);
        $this->app->bind(FileStorageInterface::class, LocalStorageAdapter::class);

        $this->app->singleton(
            \StatefulChunking\LaravelPackage\Core\Services\StatefulChunkingService::class,
            fn () => new \StatefulChunking\LaravelPackage\Core\Services\StatefulChunkingService()
        );
        $this->app->alias(
            \StatefulChunking\LaravelPackage\Core\Services\StatefulChunkingService::class,
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
                __DIR__ . '/../../config/stateful-chunking.php' => config_path('stateful-chunking.php'),
            ], 'stateful-chunking-config');

            $this->commands([
                ClearStaleSessionsCommand::class,
            ]);
        }

        if (config('stateful-chunking.routes.enabled', true)) {
            $this->configureRateLimiting();
            $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        }
    }

    /**
     * Configure rate limiting for the chunking API endpoints.
     */
    protected function configureRateLimiting(): void
    {
        if (!config('stateful-chunking.rate_limits.enabled', true)) {
            return;
        }

        $resolveKey = function (\Illuminate\Http\Request $request): string {
            $user = $request->user();
            if (is_object($user) && property_exists($user, 'id') && (is_string($user->id) || is_int($user->id))) {
                return (string) $user->id;
            }
            return $request->ip() ?? '127.0.0.1';
        };

        $getConfigLimit = function (string $key, int $default): int {
            $val = config("stateful-chunking.rate_limits.{$key}", $default);
            return is_numeric($val) ? (int) $val : $default;
        };

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-initiate', function (\Illuminate\Http\Request $request) use ($resolveKey, $getConfigLimit) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute($getConfigLimit('initiate', 10))
                ->by($resolveKey($request));
        });

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-upload', function (\Illuminate\Http\Request $request) use ($resolveKey, $getConfigLimit) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute($getConfigLimit('upload', 120))
                ->by($resolveKey($request));
        });

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-status', function (\Illuminate\Http\Request $request) use ($resolveKey, $getConfigLimit) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute($getConfigLimit('status', 60))
                ->by($resolveKey($request));
        });

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-complete', function (\Illuminate\Http\Request $request) use ($resolveKey, $getConfigLimit) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute($getConfigLimit('complete', 20))
                ->by($resolveKey($request));
        });

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-cancel', function (\Illuminate\Http\Request $request) use ($resolveKey, $getConfigLimit) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute($getConfigLimit('cancel', 20))
                ->by($resolveKey($request));
        });
    }
}

