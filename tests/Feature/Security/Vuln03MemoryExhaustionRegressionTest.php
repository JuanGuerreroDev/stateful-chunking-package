<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-03 REGRESSION TEST: Chunk Size Limit Enforcement & DoS Prevention
 *
 * Security Invariants:
 * 1. Raw body uploads exceeding chunk_size_bytes MUST be rejected with HTTP 413 (Payload Too Large).
 * 2. Multipart file uploads exceeding chunk_size_bytes MUST be rejected by FormRequest with HTTP 422.
 * 3. Initiate endpoint MUST enforce mathematical consistency between file_size and total_chunks
 *    based on chunk_size_bytes, preventing massive single-chunk declarations.
 * 4. Legitimate chunks within configured limits MUST continue to be accepted with HTTP 200.
 */
class Vuln03MemoryExhaustionRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking.rate_limits.initiate', 1000);
        Config::set('stateful-chunking.rate_limits.upload', 1000);
    }

    /**
     * REGRESSION 1: Raw body upload exceeding configured chunk limit must return HTTP 413.
     */
    public function test_raw_body_chunk_exceeding_size_limit_is_rejected_with_413(): void
    {
        RateLimiter::clear('stateful-chunking-initiate');
        RateLimiter::clear('stateful-chunking-upload');

        $configuredChunkLimit = (int) config('stateful-chunking.chunk_size_bytes', 2097152); // 2 MB

        // Craft a 3.5 MB payload (exceeding 2 MB + 10% margin)
        $oversizedData = str_repeat("DOS_RAW_BODY_TEST_PAYLOAD_BLOCK_", 120000); // ~3.84 MB
        $payloadSize = strlen($oversizedData);
        $this->assertGreaterThan($configuredChunkLimit * 1.1, $payloadSize);
        $payloadHash = hash('sha256', $oversizedData);

        // Initiate session configured for this payload (with sufficient chunks)
        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'oversized_raw_test.bin',
            'file_size'    => $payloadSize,
            'total_chunks' => 2,
            'total_hash'   => $payloadHash,
            'fingerprint'  => 'dos_reg_raw_fp_' . uniqid(),
        ]);
        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        // Send oversized chunk via raw HTTP body
        $uploadResponse = $this->call(
            method: 'POST',
            uri: '/api/chunks/upload?session_id=' . $sessionId . '&chunk_index=0&chunk_hash=' . $payloadHash,
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'CONTENT_TYPE' => 'application/octet-stream',
                'CONTENT_LENGTH' => (string) $payloadSize,
            ],
            content: $oversizedData
        );

        $this->assertEquals(
            413,
            $uploadResponse->status(),
            "Oversized raw chunk ({$payloadSize} bytes) should have been rejected with 413 Payload Too Large, got {$uploadResponse->status()}"
        );
        $this->assertStringContainsString('exceeds maximum allowed', $uploadResponse->json('message'));
    }

    /**
     * REGRESSION 2: Multipart file exceeding configured chunk limit must return HTTP 422.
     */
    public function test_multipart_file_exceeding_size_limit_is_rejected_with_422(): void
    {
        RateLimiter::clear('stateful-chunking-initiate');
        RateLimiter::clear('stateful-chunking-upload');

        $configuredChunkLimit = (int) config('stateful-chunking.chunk_size_bytes', 2097152); // 2 MB

        // Craft a 3.5 MB payload
        $oversizedData = str_repeat("DOS_MULTIPART_TEST_PAYLOAD_DATA_", 120000); // ~3.84 MB
        $payloadSize = strlen($oversizedData);
        $this->assertGreaterThan($configuredChunkLimit * 1.1, $payloadSize);
        $payloadHash = hash('sha256', $oversizedData);

        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'oversized_multipart_test.bin',
            'file_size'    => $payloadSize,
            'total_chunks' => 2,
            'total_hash'   => $payloadHash,
            'fingerprint'  => 'dos_reg_multi_fp_' . uniqid(),
        ]);
        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        $uploadedFile = UploadedFile::fake()->createWithContent('chunk_0.tmp', $oversizedData);

        $uploadResponse = $this->call(
            method: 'POST',
            uri: '/api/chunks/upload',
            parameters: [
                'session_id'  => $sessionId,
                'chunk_index' => 0,
                'chunk_hash'  => $payloadHash,
            ],
            cookies: [],
            files: ['file' => $uploadedFile],
            server: ['HTTP_ACCEPT' => 'application/json']
        );

        $this->assertEquals(
            422,
            $uploadResponse->status(),
            "Oversized multipart file should have been rejected with 422 by FormRequest, got {$uploadResponse->status()}"
        );
        $uploadResponse->assertJsonValidationErrors('file');
    }

    /**
     * REGRESSION 3: Initiate endpoint must reject mathematically inconsistent total_chunks.
     */
    public function test_initiate_rejects_inconsistent_total_chunks_for_file_size(): void
    {
        RateLimiter::clear('stateful-chunking-initiate');

        $fileSize = 50 * 1024 * 1024; // 50 MB
        // With 2 MB chunk size, 50 MB requires at least 25 chunks. Declaring 1 must be rejected.
        $insufficientChunks = 1;

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'huge_inconsistent_file.zip',
            'file_size'    => $fileSize,
            'total_chunks' => $insufficientChunks,
            'total_hash'   => hash('sha256', 'dummy_hash'),
            'fingerprint'  => 'dos_reg_init_fp_' . uniqid(),
        ]);

        $this->assertEquals(
            422,
            $response->status(),
            "Initiating 50MB file with only 1 chunk must be rejected with 422, got {$response->status()}"
        );
        $response->assertJsonValidationErrors('total_chunks');
    }

    /**
     * REGRESSION 4: Initiate endpoint accepts declared total_chunks when sufficient for file_size.
     */
    public function test_initiate_accepts_sufficient_total_chunks(): void
    {
        RateLimiter::clear('stateful-chunking-initiate');

        $fileSize = 50 * 1024 * 1024; // 50 MB
        $sufficientChunks = 25; // exactly ceil(50MB / 2MB)

        $response = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'huge_consistent_file.zip',
            'file_size'    => $fileSize,
            'total_chunks' => $sufficientChunks,
            'total_hash'   => hash('sha256', 'dummy_hash'),
            'fingerprint'  => 'dos_reg_init_ok_fp_' . uniqid(),
        ]);

        $response->assertStatus(201);
        $this->assertEquals($sufficientChunks, $response->json('data.total_chunks'));
    }

    /**
     * REGRESSION 5: Legitimate chunk within configured limit is accepted.
     */
    public function test_legitimate_chunk_within_configured_limit_is_accepted(): void
    {
        RateLimiter::clear('stateful-chunking-initiate');
        RateLimiter::clear('stateful-chunking-upload');

        // Legitimate 1.5 MB chunk (under 2 MB limit)
        $validChunkData = str_repeat("VALID_LEGITIMATE_CHUNK_BLOCK_01_", 48000); // ~1.53 MB
        $chunkSize = strlen($validChunkData);
        $chunkHash = hash('sha256', $validChunkData);

        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name'    => 'legitimate_upload.bin',
            'file_size'    => $chunkSize,
            'total_chunks' => 1,
            'total_hash'   => $chunkHash,
            'fingerprint'  => 'legit_fp_' . uniqid(),
        ]);
        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        $uploadedFile = UploadedFile::fake()->createWithContent('chunk_0.tmp', $validChunkData);

        $uploadResponse = $this->call('POST', '/api/chunks/upload', [
            'session_id'  => $sessionId,
            'chunk_index' => 0,
            'chunk_hash'  => $chunkHash,
        ], [], ['file' => $uploadedFile]);

        $uploadResponse->assertStatus(200);
        $this->assertEquals('completed', $uploadResponse->json('data.chunks_map.0'));
    }
}
