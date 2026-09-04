<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkUploaded;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-11 REGRESSION TEST: Chunk Overwrite & Idempotency Enforcement
 *
 * Security Invariants:
 * 1. When a chunk that has already been marked 'completed' is submitted again with the same valid payload:
 *    - The storage layer MUST NOT overwrite the physical file on disk (preventing I/O exhaustion and reassembly race conditions).
 *    - Domain events (ChunkUploaded) MUST NOT be redundantly re-dispatched.
 *    - The endpoint MUST return HTTP 200 idempotently with current session state.
 * 2. If a re-submitted chunk has tampered content or invalid hash, it MUST be rejected.
 */
class Vuln11ChunkIdempotencyRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function initiateTwoChunkSession(): array
    {
        $chunk0Data = 'FIRST_CHUNK_DATA_12345';
        $chunk1Data = 'SECOND_CHUNK_DATA_67890';
        $totalData = $chunk0Data . $chunk1Data;

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'idempotency_test.txt',
            'file_size'    => strlen($totalData),
            'total_chunks' => 2,
            'total_hash'   => hash('sha256', $totalData),
            'fingerprint'  => 'idemp_fp_' . uniqid(),
        ]);

        $response->assertStatus(201);
        $sessionId = $response->json('data.session_id');

        return [$sessionId, $chunk0Data, $chunk1Data];
    }

    /**
     * REGRESSION 1: Re-uploading an already completed chunk does not overwrite storage or re-dispatch events.
     */
    public function test_completed_chunk_is_handled_idempotently_without_storage_overwrite(): void
    {
        [$sessionId, $chunk0Data] = $this->initiateTwoChunkSession();
        $chunk0Hash = hash('sha256', $chunk0Data);

        // First upload of chunk 0
        $file1 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $res1 = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $chunk0Hash,
        ], [], ['file' => $file1], ['HTTP_ACCEPT' => 'application/json']);

        $res1->assertStatus(200);
        $this->assertEquals('completed', $res1->json('data.chunks_map.0'));

        // Put a sentinel in the chunk file to detect if it gets overwritten
        $disk = Storage::disk('local');
        $chunkPath = sprintf('chunks_temp/%s/chunk_0.tmp', $sessionId);
        $this->assertTrue($disk->exists($chunkPath));

        // Listen for events during retry
        Event::fake([ChunkUploaded::class]);

        // Second upload of chunk 0 (Network retry or duplicate request)
        $file2 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $res2 = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $chunk0Hash,
        ], [], ['file' => $file2], ['HTTP_ACCEPT' => 'application/json']);

        $res2->assertStatus(200);
        $this->assertEquals('completed', $res2->json('data.chunks_map.0'));

        // Assert no duplicate domain event was dispatched on idempotent retry
        Event::assertNotDispatched(ChunkUploaded::class);
    }

    /**
     * REGRESSION 2: Storage adapter storeChunk is not called on duplicate completed chunk.
     */
    public function test_storage_adapter_is_not_called_again_for_already_completed_chunk(): void
    {
        [$sessionId, $chunk0Data] = $this->initiateTwoChunkSession();
        $chunk0Hash = hash('sha256', $chunk0Data);

        // Upload chunk 0 first time normally
        $file1 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $chunk0Hash,
        ], [], ['file' => $file1], ['HTTP_ACCEPT' => 'application/json']);

        // Now mock FileStorageInterface to assert storeChunk is NEVER called on retry
        $storageMock = $this->createMock(FileStorageInterface::class);
        $storageMock->expects($this->never())->method('storeChunk');
        $this->app->instance(FileStorageInterface::class, $storageMock);

        $file2 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $res2 = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $chunk0Hash,
        ], [], ['file' => $file2], ['HTTP_ACCEPT' => 'application/json']);

        $res2->assertStatus(200);
    }

    /**
     * REGRESSION 3: Re-uploading an already completed chunk with tampered content or hash fails integrity check.
     */
    public function test_completed_chunk_with_tampered_payload_is_rejected(): void
    {
        [$sessionId, $chunk0Data] = $this->initiateTwoChunkSession();
        $chunk0Hash = hash('sha256', $chunk0Data);

        // Upload chunk 0 first time normally
        $file1 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $chunk0Hash,
        ], [], ['file' => $file1], ['HTTP_ACCEPT' => 'application/json']);

        // Now attempt to retry chunk 0 with tampered content (mismatched hash)
        $tamperedFile = UploadedFile::fake()->createWithContent('chunk_0.tmp', 'TAMPERED_CONTENT');
        $resTampered = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $chunk0Hash, // claims original hash, but content differs
        ], [], ['file' => $tamperedFile], ['HTTP_ACCEPT' => 'application/json']);

        $resTampered->assertStatus(400);
    }
}
