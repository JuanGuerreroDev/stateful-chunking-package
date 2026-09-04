<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-06 REGRESSION TEST: Information Disclosure of Server Paths in /complete
 *
 * Security Invariants:
 * 1. By default, POST /api/chunks/complete MUST NOT expose internal filesystem paths
 *    ('path' or 'relative_path') to public HTTP clients (CWE-200).
 * 2. The client must exclusively receive the encrypted, opaque 'upload_token'.
 * 3. The backend application resolves the real filesystem path via StatefulChunkingService::resolveToken().
 * 4. Opt-in via config('stateful-chunking.expose_server_paths', true) allows exposing paths only when explicitly configured.
 */
class Vuln06PathDisclosureRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Config::set('stateful-chunking.rate_limits.initiate', 1000);
        Config::set('stateful-chunking.rate_limits.upload', 1000);
        Config::set('stateful-chunking.rate_limits.complete', 1000);

        RateLimiter::clear('stateful-chunking-initiate');
        RateLimiter::clear('stateful-chunking-upload');
        RateLimiter::clear('stateful-chunking-complete');
    }

    private function completeUploadSession(string $fileName = 'confidential.pdf', string $content = 'TOP SECRET CONTENT'): array
    {
        $hash = hash('sha256', $content);

        $initiate = $this->postJson('/api/chunks/initiate', [
            'file_name'    => $fileName,
            'file_size'    => strlen($content),
            'total_chunks' => 1,
            'total_hash'   => $hash,
            'fingerprint'  => 'vuln06_fp_' . uniqid(),
        ]);
        $initiate->assertStatus(201);
        $sessionId = (string) $initiate->json('data.session_id');

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);
        $upload = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $hash,
        ], [], ['file' => $file]);
        $upload->assertStatus(200);

        $complete = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionId,
        ]);
        $complete->assertStatus(200);

        return [
            'response'   => $complete,
            'session_id' => $sessionId,
            'hash'       => $hash,
        ];
    }

    /**
     * REGRESSION 1: /complete does NOT return internal server paths by default.
     */
    public function test_complete_response_omits_internal_paths_by_default(): void
    {
        Config::set('stateful-chunking.expose_server_paths', false);

        $result = $this->completeUploadSession();
        $response = $result['response'];
        $data = $response->json('data');

        // Sensitive server filesystem paths MUST NOT be present
        $this->assertArrayNotHasKey(
            'path',
            $data,
            'SECURITY VULNERABILITY: Internal server path is leaked in /complete response!'
        );
        $this->assertArrayNotHasKey(
            'relative_path',
            $data,
            'SECURITY VULNERABILITY: Internal relative_path is leaked in /complete response!'
        );

        // Required public fields must be present
        $this->assertArrayHasKey('upload_token', $data);
        $this->assertArrayHasKey('session_id', $data);
        $this->assertArrayHasKey('file_name', $data);
        $this->assertArrayHasKey('file_size', $data);
        $this->assertArrayHasKey('computed_hash', $data);
        $this->assertTrue($data['verified']);

        // Backend can resolve the real path from upload_token securely
        $uploadToken = (string) $data['upload_token'];
        $stagedFile = app(StatefulChunkingService::class)->resolveToken($uploadToken);
        $this->assertTrue($stagedFile->isValid);
        $this->assertNotEmpty($stagedFile->tempPath);
        $this->assertStringContainsString($result['session_id'], $stagedFile->tempPath);
    }

    /**
     * REGRESSION 2: Server paths are included only when explicitly enabled via configuration.
     */
    public function test_complete_response_includes_paths_only_when_explicitly_configured(): void
    {
        Config::set('stateful-chunking.expose_server_paths', true);

        $result = $this->completeUploadSession();
        $response = $result['response'];
        $data = $response->json('data');

        // When explicitly opted-in, paths are present
        $this->assertArrayHasKey('path', $data);
        $this->assertArrayHasKey('relative_path', $data);
        $this->assertArrayHasKey('upload_token', $data);
    }
}
