<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Drivers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

class FileDriverFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'file');
        config()->set('stateful-chunking.cache_store', 'file');
        Storage::fake('local');
    }

    public function test_file_cache_driver_persists_session_and_completes_upload(): void
    {
        $chunk0Data = "FILE DRIVER TEST CHUNK 0 - MULTI-DRIVER ARCHITECTURE TEST.";
        $chunk1Data = "FILE DRIVER TEST CHUNK 1 - RESILIENT STATE PERSISTED ON DISK.";
        $fullContent = $chunk0Data . $chunk1Data;

        $chunk0Hash = hash('sha256', $chunk0Data);
        $chunk1Hash = hash('sha256', $chunk1Data);
        $totalHash = hash('sha256', $fullContent);

        // 1. Initiate upload session
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'file_driver_test.txt',
            'file_size' => strlen($fullContent),
            'total_chunks' => 2,
            'total_hash' => $totalHash,
            'fingerprint' => 'file_driver_fp_' . time(),
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');
        $this->assertNotEmpty($sessionId);
        $this->assertTrue($initiateResponse->json('data.remaining_ttl') > 0);
        $this->assertFalse($initiateResponse->json('data.is_expired'));

        // 2. Upload Chunk 0
        $file0 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $upload0Response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $chunk0Hash,
        ], [], ['file' => $file0]);

        $upload0Response->assertStatus(200);

        // 3. Status check from file cache store
        $statusResponse = $this->getJson("/api/chunks/status/{$sessionId}");
        $statusResponse->assertStatus(200);
        $this->assertEquals('completed', $statusResponse->json('data.chunks_map.0'));
        $this->assertEquals('pending', $statusResponse->json('data.chunks_map.1'));

        // 4. Upload Chunk 1 (Completes upload)
        $file1 = UploadedFile::fake()->createWithContent('chunk_1.tmp', $chunk1Data);
        $upload1Response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 1,
            'chunk_hash' => $chunk1Hash,
        ], [], ['file' => $file1]);

        $upload1Response->assertStatus(200);
        $this->assertEquals('completed', $upload1Response->json('data.status'));

        // 5. Complete and reassemble
        $completeResponse = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionId,
        ]);
        $completeResponse->assertStatus(200);
        $this->assertTrue($completeResponse->json('data.verified'));
        $this->assertNotEmpty($completeResponse->json('data.upload_token'));
    }

    public function test_file_cache_driver_purges_state_on_cancellation(): void
    {
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'to_cancel.txt',
            'file_size' => 1024,
            'total_chunks' => 2,
            'total_hash' => hash('sha256', 'dummy'),
            'fingerprint' => 'file_cancel_fp_' . time(),
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        // Cancel session
        $cancelResponse = $this->deleteJson("/api/chunks/cancel/{$sessionId}");
        $cancelResponse->assertStatus(200);

        // Verify status returns 404
        $statusResponse = $this->getJson("/api/chunks/status/{$sessionId}");
        $statusResponse->assertStatus(404);
    }
}
