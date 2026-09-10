<?php

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

class RateLimitingAndMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('stateful-chunking-upload.initiate');
        RateLimiter::clear('stateful-chunking-upload.upload');
        RateLimiter::clear('stateful-chunking-upload.status');
        RateLimiter::clear('stateful-chunking-upload.complete');
        RateLimiter::clear('stateful-chunking-upload.cancel');
    }

    public function test_initiate_endpoint_has_differentiated_rate_limiting(): void
    {
        Config::set('stateful-chunking-upload.rate_limits.enabled', true);
        Config::set('stateful-chunking-upload.rate_limits.initiate', 2);

        // First 2 requests should not be throttled (will fail on validation or logic, but not 429)
        $response1 = $this->postJson('/api/chunks/initiate', []);
        $this->assertNotEquals(429, $response1->getStatusCode());

        $response2 = $this->postJson('/api/chunks/initiate', []);
        $this->assertNotEquals(429, $response2->getStatusCode());

        // 3rd request within same minute should hit 429 Too Many Requests
        $response3 = $this->postJson('/api/chunks/initiate', []);
        $this->assertEquals(429, $response3->getStatusCode());
    }

    public function test_upload_endpoint_has_differentiated_rate_limiting(): void
    {
        Config::set('stateful-chunking-upload.rate_limits.enabled', true);
        Config::set('stateful-chunking-upload.rate_limits.upload', 3);

        for ($i = 0; $i < 3; $i++) {
            $res = $this->postJson('/api/chunks/upload', []);
            $this->assertNotEquals(429, $res->getStatusCode());
        }

        $throttled = $this->postJson('/api/chunks/upload', []);
        $this->assertEquals(429, $throttled->getStatusCode());
    }

    public function test_rate_limiting_can_be_disabled_via_config(): void
    {
        Config::set('stateful-chunking-upload.rate_limits.enabled', false);
        Config::set('stateful-chunking-upload.rate_limits.initiate', 1);

        // Re-register routes with disabled rate limits
        $this->app->make('router')->getRoutes()->refreshNameLookups();
        require __DIR__.'/../../routes/api.php';

        RateLimiter::clear('stateful-chunking-upload.initiate');

        $res1 = $this->postJson('/api/chunks/initiate', []);
        $res2 = $this->postJson('/api/chunks/initiate', []);

        $this->assertNotEquals(429, $res1->getStatusCode());
        $this->assertNotEquals(429, $res2->getStatusCode());
    }
}
