<?php

namespace Juanoecr\StatefulChunking\Tests;

use Juanoecr\StatefulChunking\Providers\StatefulChunkingServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            StatefulChunkingServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:ykmnx42QZZwEkFiKmTXKKdoSMmJxQonk56uhSbWKYvU=');
        // Reflect production: unexpected exceptions must render sanitised, never leak traces.
        $app['config']->set('app.debug', false);
    }
}
