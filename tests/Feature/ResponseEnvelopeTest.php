<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

/**
 * Pins the response envelope's public-projection policy (ChunkingResponse).
 *
 * The envelope is an explicit allowlist: it must never echo the session's
 * `owner_id` (a caller identifier such as `user:42` / `ip:...`), and it must
 * withhold the assembled file's server path unless `expose_server_paths` is on.
 * These are the PII / path-disclosure guarantees that let the controller stay a
 * thin adapter, so they get their own regression.
 */
class ResponseEnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking-upload.rate_limits.enabled', false);
        foreach (['initiate', 'upload', 'complete'] as $op) {
            RateLimiter::clear('stateful-chunking-upload-'.$op);
        }
    }

    /**
     * @return array{0: string, 1: string} [sessionId, totalHash]
     */
    private function initiateAndUploadSingleChunk(string $content): array
    {
        $hash = hash('sha256', $content);

        $init = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'envelope.bin',
            'file_size' => strlen($content),
            'total_chunks' => 1,
            'total_hash' => $hash,
            'fingerprint' => 'env_'.uniqid(),
        ]);
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);
        $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $hash,
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json'])->assertStatus(200);

        return [$sessionId, $hash];
    }

    public function test_initiate_response_does_not_leak_owner_id(): void
    {
        $response = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'no-pii.bin',
            'file_size' => 512,
            'total_chunks' => 1,
            'total_hash' => hash('sha256', 'x'),
            'fingerprint' => 'pii_'.uniqid(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonMissingPath('data.owner_id');
        // The useful public fields are still present.
        $response->assertJsonStructure(['message', 'data' => ['session_id', 'status', 'pending_chunks']]);
    }

    public function test_status_response_does_not_leak_owner_id(): void
    {
        [$sessionId] = $this->initiateAndUploadSingleChunk(str_repeat('X', 128));

        $response = $this->getJson("/api/chunks/status/{$sessionId}");

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.owner_id');
    }

    public function test_complete_response_withholds_server_paths_by_default(): void
    {
        [$sessionId] = $this->initiateAndUploadSingleChunk(str_repeat('Y', 256));

        $response = $this->postJson('/api/chunks/complete', ['session_id' => $sessionId]);

        $response->assertStatus(200);
        $response->assertJsonMissingPath('data.path');
        $response->assertJsonMissingPath('data.relative_path');
        // The opaque token the consumer actually uses is present.
        $this->assertNotEmpty($response->json('data.upload_token'));
        $this->assertTrue($response->json('data.verified'));
    }

    public function test_complete_response_exposes_paths_only_when_configured(): void
    {
        Config::set('stateful-chunking-upload.expose_server_paths', true);

        [$sessionId] = $this->initiateAndUploadSingleChunk(str_repeat('Z', 256));

        $response = $this->postJson('/api/chunks/complete', ['session_id' => $sessionId]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.verified', true);
        $this->assertNotEmpty($response->json('data.path'));
        $this->assertNotEmpty($response->json('data.relative_path'));
    }
}
