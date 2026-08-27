<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Providers;

use Illuminate\Support\ServiceProvider;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Core\Contracts\FileStorageInterface;
use StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Repositories\RedisStateRepository;
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

        $this->app->bind(StateRepositoryInterface::class, function ($app) {
            $driver = config('stateful-chunking.driver', config('cache.default', 'file'));

            if ($driver === 'redis') {
                return $app->make(RedisStateRepository::class);
            }

            return $app->make(CacheStateRepository::class);
        });

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
            $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        }
    }
}

