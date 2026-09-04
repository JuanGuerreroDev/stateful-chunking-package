<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-09 REGRESSION TEST: FormRequest Authorization & require_auth enforcement
 *
 * Security Invariants:
 * 1. When config('stateful-chunking.require_auth') is false (default), anonymous/guest requests are authorized.
 * 2. When config('stateful-chunking.require_auth') is true:
 *    - Unauthenticated requests to /initiate MUST be rejected with HTTP 403 Forbidden.
 *    - Unauthenticated requests to /upload MUST be rejected with HTTP 403 Forbidden.
 *    - Authenticated users (implementing Authenticatable) are authorized and succeed.
 */
class Vuln09AuthenticationAuthorizationRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Config::set('stateful-chunking.rate_limits.initiate', 1000);
        Config::set('stateful-chunking.rate_limits.upload', 1000);

        RateLimiter::clear('stateful-chunking-initiate');
        RateLimiter::clear('stateful-chunking-upload');
    }

    private function validInitiatePayload(): array
    {
        return [
            'file_name'    => 'auth_test_doc.txt',
            'file_size'    => 1024,
            'total_chunks' => 1,
            'total_hash'   => str_repeat('a', 64),
            'fingerprint'  => 'auth_fp_' . uniqid(),
        ];
    }

    /**
     * REGRESSION 1: By default (require_auth = false), anonymous requests are permitted.
     */
    public function test_anonymous_requests_permitted_by_default(): void
    {
        Config::set('stateful-chunking.require_auth', false);

        $response = $this->postJson('/api/chunks/initiate', $this->validInitiatePayload());
        $response->assertStatus(201);
    }

    /**
     * REGRESSION 2: When require_auth = true, anonymous initiate request is rejected with 403.
     */
    public function test_anonymous_initiate_rejected_with_403_when_require_auth_is_true(): void
    {
        Config::set('stateful-chunking.require_auth', true);

        $response = $this->postJson('/api/chunks/initiate', $this->validInitiatePayload());

        $this->assertEquals(
            403,
            $response->status(),
            "Expected 403 Forbidden for anonymous initiate when require_auth=true, got {$response->status()}"
        );
        $response->assertJson(['message' => 'This action is unauthorized.']);
    }

    /**
     * REGRESSION 3: When require_auth = true, anonymous upload request is rejected with 403.
     */
    public function test_anonymous_upload_rejected_with_403_when_require_auth_is_true(): void
    {
        Config::set('stateful-chunking.require_auth', true);

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', 'TEST CHUNK DATA');
        $response = $this->call(
            'POST',
            '/api/chunks/upload',
            [
                'session_id'  => 'a0000000-0000-0000-0000-000000000001',
                'chunk_index' => 0,
                'chunk_hash'  => hash('sha256', 'TEST CHUNK DATA'),
            ],
            [],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $this->assertEquals(
            403,
            $response->status(),
            "Expected 403 Forbidden for anonymous upload when require_auth=true, got {$response->status()}"
        );
        $response->assertJson(['message' => 'This action is unauthorized.']);
    }

    /**
     * REGRESSION 4: When require_auth = true, authenticated user is authorized and succeeds.
     */
    public function test_authenticated_user_is_authorized_when_require_auth_is_true(): void
    {
        Config::set('stateful-chunking.require_auth', true);

        $user = new GenericUser(['id' => 42, 'name' => 'Juan Guerrero']);
        $this->actingAs($user);

        $response = $this->postJson('/api/chunks/initiate', $this->validInitiatePayload());
        $response->assertStatus(201);
    }
}
