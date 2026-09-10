<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

/**
 * Locks the HTTP contract produced by the typed domain exceptions: each domain
 * failure maps to a semantically correct status with a generic, non-leaking
 * message. The controller carries no try/catch — these responses are rendered by
 * the exceptions themselves (Responsable) via Laravel's handler.
 */
class DomainExceptionMappingTest extends TestCase
{
    private const VALID_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const VALID_UUID = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        foreach (['initiate', 'upload', 'status', 'complete', 'cancel'] as $op) {
            RateLimiter::clear('stateful-chunking-upload-'.$op);
        }
    }

    public function test_status_of_unknown_session_maps_to_404(): void
    {
        $response = $this->getJson('/api/chunks/status/'.self::VALID_UUID);

        $response->assertStatus(404)
            ->assertJson(['message' => 'Upload session not found.']);
    }

    public function test_upload_to_unknown_session_maps_to_404(): void
    {
        $content = 'chunk-body';
        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);

        $response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => self::VALID_UUID,
            'chunk_index' => 0,
            'chunk_hash' => hash('sha256', $content),
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(404, $response->status());
    }

    public function test_reassembling_incomplete_session_maps_to_409(): void
    {
        // Declare a 2-chunk session but upload nothing, then request completion.
        $chunkSize = (int) config('stateful-chunking-upload.chunk_size_bytes', 2097152);
        $init = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'incomplete.bin',
            'file_size' => $chunkSize + 10,   // needs 2 chunks
            'total_chunks' => 2,
            'total_hash' => self::VALID_SHA256,
            'fingerprint' => 'not_ready_'.uniqid(),
        ]);
        $init->assertStatus(201);
        $sessionId = (string) $init->json('data.session_id');

        $response = $this->postJson('/api/chunks/complete', ['session_id' => $sessionId]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'Upload session is not ready for reassembly.']);
    }

    public function test_domain_exception_response_never_leaks_internals(): void
    {
        $response = $this->postJson('/api/chunks/complete', ['session_id' => self::VALID_UUID]);

        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('Exception', $message);
        $this->assertStringNotContainsString('/', $message);
    }
}
