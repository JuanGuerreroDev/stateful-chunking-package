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

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-initiate', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('stateful-chunking.rate_limits.initiate', 10))
                ->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-upload', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('stateful-chunking.rate_limits.upload', 120))
                ->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-status', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('stateful-chunking.rate_limits.status', 60))
                ->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-complete', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('stateful-chunking.rate_limits.complete', 20))
                ->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('stateful-chunking-cancel', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('stateful-chunking.rate_limits.cancel', 20))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}

