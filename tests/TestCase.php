<?php

namespace StatefulChunking\LaravelPackage\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use StatefulChunking\LaravelPackage\Providers\StatefulChunkingServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            StatefulChunkingServiceProvider::class,
        ];
    }
}
