<?php

namespace StatefulChunking\LaravelPackage\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use StatefulChunking\LaravelPackage\Tests\TestCase;

class E2EPackageIntegrationTest extends TestCase
{
    public function test_full_e2e_chunked_upload_lifecycle_with_security_controls(): void
    {
        Storage::fake('local');

        // 1. Prepare raw content & cryptographic hashes
        $chunk0Data = "FUNCTIONAL TEST CHUNK 0 - STATEFUL CHUNKING PACKAGE FOR LARAVEL.";
        $chunk1Data = "FUNCTIONAL TEST CHUNK 1 - VERIFIED RESILIENT CACHE REASSEMBLY.";
        $fullContent = $chunk0Data . $chunk1Data;

        $chunk0Hash = hash('sha256', $chunk0Data);
        $chunk1Hash = hash('sha256', $chunk1Data);
        $totalHash = hash('sha256', $fullContent);

        // STEP 1: Initiate Upload Session
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'e2e_verified_document.txt',
            'file_size' => strlen($fullContent),
            'total_chunks' => 2,
            'total_hash' => $totalHash,
            'fingerprint' => 'e2e_fp_' . time(),
        ]);

        $initiateResponse->assertStatus(201);
        $sessionId = $initiateResponse->json('data.session_id');
        $this->assertNotEmpty($sessionId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionId);

        // STEP 2: Upload Chunk #0 via Multipart Form-Data
        $file0 = UploadedFile::fake()->createWithContent('chunk_0.tmp', $chunk0Data);
        $upload0Response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => $chunk0Hash,
        ], [], ['file' => $file0]);

        $upload0Response->assertStatus(200);

        // STEP 3: Check Session Status Midway
        $statusResponse = $this->getJson("/api/chunks/status/{$sessionId}");
        $statusResponse->assertStatus(200);
        $statusData = $statusResponse->json('data');
        $this->assertEquals('completed', $statusData['chunks_map'][0]);
        $this->assertEquals('pending', $statusData['chunks_map'][1]);

        // STEP 4: Upload Chunk #1 via Multipart Form-Data
        $file1 = UploadedFile::fake()->createWithContent('chunk_1.tmp', $chunk1Data);
        $upload1Response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 1,
            'chunk_hash' => $chunk1Hash,
        ], [], ['file' => $file1]);

        $upload1Response->assertStatus(200);

        // STEP 5: Request File Reassembly & Completion
        $completeResponse = $this->postJson('/api/chunks/complete', [
            'session_id' => $sessionId,
        ]);

        $completeResponse->assertStatus(200);
        $reassembleData = $completeResponse->json('data');

        $this->assertEquals($totalHash, $reassembleData['computed_hash']);
        $this->assertTrue($reassembleData['verified']);

        // Verify assembled file content on storage
        $storedPath = $reassembleData['relative_path'];
        Storage::disk('local')->assertExists($storedPath);
        $assembledContent = Storage::disk('local')->get($storedPath);
        $this->assertEquals($fullContent, $assembledContent);
    }
}
