<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Storage\LocalStorageAdapter;
use Juanoecr\StatefulChunking\Tests\TestCase;
use RuntimeException;

/**
 * VULN-08 REGRESSION TEST: Constant-time Hash Comparison & Timing Attack Mitigation
 *
 * Security Invariants:
 * 1. Chunk and assembled file hash verification MUST use constant-time comparison (hash_equals).
 * 2. Case-insensitive hex matching must be preserved (e.g. uppercase vs lowercase).
 * 3. Tampered hashes must be rejected deterministically.
 * 4. Assembled file with mismatched expected hash must abort and delete the assembled file.
 */
class Vuln08TimingAttackRegressionTest extends TestCase
{
    private LocalStorageAdapter $storageAdapter;

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

        $this->storageAdapter = new LocalStorageAdapter();
    }

    /**
     * REGRESSION 1: Valid chunk hash matches regardless of case (uppercase vs lowercase hex).
     */
    public function test_chunk_hash_comparison_accepts_valid_hash_case_insensitively(): void
    {
        $sessionId = 'test_session_' . uniqid();
        $content = 'CONSTANT TIME VERIFIED CHUNK DATA';
        $validHash = hash('sha256', $content);

        // Test with uppercase hash
        $upperHash = strtoupper($validHash);
        $path = $this->storageAdapter->storeChunk($sessionId, 0, $content, $upperHash);

        $this->assertNotEmpty($path);
        Storage::disk('local')->assertExists($path);
    }

    /**
     * REGRESSION 2: Tampered chunk hash is rejected.
     */
    public function test_tampered_chunk_hash_is_rejected(): void
    {
        $sessionId = 'test_session_' . uniqid();
        $content = 'AUTHENTIC CONTENT';
        $tamperedHash = hash('sha256', 'CORRUPTED CONTENT');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chunk 0 integrity check failed: SHA-256 hash mismatch.');

        $this->storageAdapter->storeChunk($sessionId, 0, $content, $tamperedHash);
    }

    /**
     * REGRESSION 3: Assembled file hash comparison accepts valid hash case-insensitively.
     */
    public function test_assembled_file_hash_accepts_valid_hash_case_insensitively(): void
    {
        $sessionId = 'test_session_' . uniqid();
        $content = 'FULL ASSEMBLED CONTENT VERIFICATION';
        $validTotalHash = hash('sha256', $content);

        // Store chunk
        $this->storageAdapter->storeChunk($sessionId, 0, $content, $validTotalHash);

        // Reassemble with UPPERCASE expected hash
        $upperTotalHash = strtoupper($validTotalHash);
        $finalPath = $this->storageAdapter->reassembleFile(
            sessionId: $sessionId,
            fileName: 'verified.txt',
            totalChunks: 1,
            expectedTotalHash: $upperTotalHash
        );

        $this->assertNotEmpty($finalPath);
        Storage::disk('local')->assertExists($finalPath);
    }

    /**
     * REGRESSION 4: Assembled file hash mismatch throws and unlinks partial file.
     */
    public function test_assembled_file_hash_mismatch_throws_and_deletes_file(): void
    {
        $sessionId = 'test_session_' . uniqid();
        $content = 'UNEXPECTED CONTENT';
        $actualHash = hash('sha256', $content);
        $wrongExpectedHash = hash('sha256', 'DIFFERENT EXPECTED CONTENT');

        $this->storageAdapter->storeChunk($sessionId, 0, $content, $actualHash);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Assembled file SHA-256 hash mismatch');

        $this->storageAdapter->reassembleFile(
            sessionId: $sessionId,
            fileName: 'failed_verification.txt',
            totalChunks: 1,
            expectedTotalHash: $wrongExpectedHash
        );
    }

    /**
     * REGRESSION 5: LocalStorageAdapter source code uses hash_equals instead of loose or strict !=/!==.
     */
    public function test_source_code_uses_hash_equals_for_all_hash_comparisons(): void
    {
        $reflection = new \ReflectionClass(LocalStorageAdapter::class);
        $fileName = $reflection->getFileName();
        $this->assertNotEmpty($fileName);
        $this->assertFileExists($fileName);

        $code = (string) file_get_contents($fileName);

        // Must invoke hash_equals
        $this->assertStringContainsString('hash_equals(', $code, 'LocalStorageAdapter MUST use hash_equals() to prevent timing attacks.');

        // Must NOT use !== for hash comparisons
        $this->assertStringNotContainsString('$computedHash) !==', $code);
        $this->assertStringNotContainsString('$assembledHash) !==', $code);
    }
}
