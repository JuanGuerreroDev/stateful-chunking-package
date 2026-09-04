<?php

namespace Juanoecr\StatefulChunking\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Juanoecr\StatefulChunking\Providers\StatefulChunkingServiceProvider;

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
    }
}
