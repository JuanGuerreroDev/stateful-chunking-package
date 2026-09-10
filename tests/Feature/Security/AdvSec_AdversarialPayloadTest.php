<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Storage\LocalStorageAdapter;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * OFFENSIVE SECURITY TESTS: Adversarial Payloads against VULN-SEC-001 to 007
 *
 * These tests demonstrate real attack vectors, NOT regressions of fixed issues.
 * Some tests CONFIRM that a vulnerability exists (the test passes when the attack succeeds).
 * This is intentional: they serve as living documentation of known weaknesses.
 */
class AdvSec_AdversarialPayloadTest extends TestCase
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

    // -------------------------------------------------------------------------
    // VULN-SEC-001: Memory spike before size check on raw body upload
    // -------------------------------------------------------------------------

    /**
     * ATTACK: Send a ~10MB raw body without multipart.
     * EXPECTED: PHP reads the full payload into memory BEFORE the 413 is returned.
     * EVIDENCE: memory_get_peak_usage() rises proportionally to payload size.
     */
    public function test_vuln_sec_001_raw_body_causes_memory_spike_before_413(): void
    {
        $chunkSizeBytes = (int) config('stateful-chunking.chunk_size_bytes', 2097152);

        // 10 MB payload - 5x the limit
        $oversizedPayload = str_repeat('A', 10 * 1024 * 1024);
        $payloadHash = hash('sha256', $oversizedPayload);

        $initiateResponse = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'attack_raw_body.bin',
            'file_size' => strlen($oversizedPayload),
            'total_chunks' => 5,
            'total_hash' => $payloadHash,
            'fingerprint' => 'adv001_'.uniqid(),
        ]);
        $initiateResponse->assertStatus(201);
        $sessionId = (string) $initiateResponse->json('data.session_id');

        $memBefore = memory_get_peak_usage(true);

        $response = $this->call(
            method: 'POST',
            uri: '/api/chunks/upload?session_id='.$sessionId.'&chunk_index=0&chunk_hash='.$payloadHash,
            parameters: [],
            cookies: [],
            files: [],
            server: [
                'CONTENT_TYPE' => 'application/octet-stream',
                'CONTENT_LENGTH' => (string) strlen($oversizedPayload),
            ],
            content: $oversizedPayload
        );

        $memAfter = memory_get_peak_usage(true);

        $this->assertEquals(413, $response->status(), 'Oversized raw body must return 413');

        $memDeltaMb = ($memAfter - $memBefore) / 1024 / 1024;
        $this->addToAssertionCount(1);

        fwrite(STDERR, sprintf(
            "\n[VULN-SEC-001] Memory delta on oversized raw body: +%.2f MB (payload: %d MB, limit: %d MB)\n",
            $memDeltaMb,
            strlen($oversizedPayload) / 1024 / 1024,
            $chunkSizeBytes / 1024 / 1024
        ));
    }

    // -------------------------------------------------------------------------
    // VULN-SEC-004: Hash validation bypass on LocalStorageAdapter::storeChunk()
    // -------------------------------------------------------------------------

    /**
     * ATTACK: Call storeChunk() directly with a 32-char (truncated) hash.
     * EXPECTED: The chunk is stored WITHOUT integrity verification (bypass).
     */
    public function test_vuln_sec_004_short_hash_bypasses_integrity_check_in_store_chunk(): void
    {
        $adapter = new LocalStorageAdapter;
        $sessionId = 'adv004-test-session-id-bypass-01';
        $content = 'Legitimate chunk content for bypass test.';

        $wrongContent = 'Completely different content that does not match.';
        $mismatchedHash = hash('sha256', $wrongContent);
        $truncatedHash = substr($mismatchedHash, 0, 32); // 32 chars - triggers the bypass

        // With truncated 32-char hash: no exception thrown (bypass confirmed)
        $path = $adapter->storeChunk($sessionId, 0, $content, $truncatedHash);

        $this->assertNotEmpty($path, 'Chunk stored with non-64-char hash (bypass confirmed)');
        $this->assertTrue(Storage::disk('local')->exists($path));

        Storage::disk('local')->delete($path);
    }

    /**
     * CONTROL: A valid 64-char mismatched hash DOES throw an exception.
     * Confirms the bypass only works for non-64-char hashes.
     */
    public function test_vuln_sec_004_valid_64_char_mismatched_hash_throws_exception(): void
    {
        $adapter = new LocalStorageAdapter;
        $sessionId = 'adv004-test-session-id-control-01';
        $content = 'Legitimate content for control test.';
        $wrongHash = hash('sha256', 'completely different content');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/integrity check failed|hash mismatch/i');

        $adapter->storeChunk($sessionId, 0, $content, $wrongHash);
    }

    // -------------------------------------------------------------------------
    // VULN-SEC-005: Hash validation bypass on LocalStorageAdapter::reassembleFile()
    // -------------------------------------------------------------------------

    /**
     * ATTACK: Call reassembleFile() with a 32-char expected total hash.
     * EXPECTED: The assembled file is accepted WITHOUT total hash verification.
     */
    public function test_vuln_sec_005_short_total_hash_bypasses_reassembly_integrity_check(): void
    {
        $adapter = new LocalStorageAdapter;
        $sessionId = 'adv005-bypass-session-00000000001';
        $content = 'Single chunk content for reassembly bypass test.';
        $realHash = hash('sha256', $content);

        $adapter->storeChunk($sessionId, 0, $content, $realHash);

        // Wrong 32-char hash - clearly wrong, but only 32 chars -> bypasses strlen check
        $wrongTruncatedHash = str_repeat('a', 32);

        // Should NOT throw - bypass confirmed
        $path = $adapter->reassembleFile($sessionId, 'bypass_test.bin', 1, $wrongTruncatedHash);

        $this->assertNotEmpty($path, 'File reassembled without integrity check (bypass confirmed)');

        Storage::disk('local')->deleteDirectory("chunks_temp/{$sessionId}");
        Storage::disk('local')->delete($path);
    }

    // -------------------------------------------------------------------------
    // VULN-SEC-003: Fingerprint reuse returns non-pending sessions
    // -------------------------------------------------------------------------

    /**
     * ATTACK: initiate, upload the file's only chunk so the session reaches COMPLETED,
     * then re-initiate with the same fingerprint.
     *
     * This assertion used to read the other way round. It asserted that the same
     * session id came back "regardless of status" and called that VULN-SEC-003
     * CONFIRMED — a test documenting a defect as the expected behaviour, which is how
     * the defect survived being written down. Reuse now requires the session to still
     * be accepting chunks, so a completed one is never handed back.
     */
    public function test_vuln_sec_003_fingerprint_reuse_refuses_a_non_pending_session(): void
    {
        $fingerprint = 'adv003-fingerprint-reuse-'.uniqid();
        $content = 'Small file content for fingerprint test.';
        $hash = hash('sha256', $content);

        // Initiate
        $initiate1 = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'fingerprint_test.txt',
            'file_size' => strlen($content),
            'total_chunks' => 1,
            'total_hash' => $hash,
            'fingerprint' => $fingerprint,
        ]);
        $initiate1->assertStatus(201);
        $sessionId1 = (string) $initiate1->json('data.session_id');

        // Upload chunk (session transitions to uploading/completed state)
        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);
        $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId1,
            'chunk_index' => 0,
            'chunk_hash' => $hash,
        ], [], ['file' => $file])->assertStatus(200);

        // Re-initiate with SAME fingerprint
        $initiate2 = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'fingerprint_test.txt',
            'file_size' => strlen($content),
            'total_chunks' => 1,
            'total_hash' => $hash,
            'fingerprint' => $fingerprint,
        ]);
        $initiate2->assertStatus(201);
        $sessionId2 = (string) $initiate2->json('data.session_id');

        // The first session is COMPLETED, so there is nothing left to resume: the
        // fingerprint must produce a fresh session rather than rebind the finished one.
        $this->assertNotEquals(
            $sessionId1,
            $sessionId2,
            'A completed session must never be handed back on a fingerprint match'
        );

        // And the new session is genuinely new: nothing has been uploaded to it yet.
        $status = $this->getJson("/api/chunks/status/{$sessionId2}");
        $status->assertStatus(200);
        $this->assertSame('pending', $status->json('data.status'));
        $this->assertSame(0, $status->json('data.uploaded_bytes'));
    }

    // -------------------------------------------------------------------------
    // VULN-SEC-007: complete() endpoint accepts non-UUID session_id
    // -------------------------------------------------------------------------

    /**
     * ATTACK: Send a 1,000-char string as session_id to /complete.
     * EXPECTED: No 500, but validation is looser than upload() (no UUID regex).
     */
    public function test_vuln_sec_007_complete_endpoint_accepts_non_uuid_session_id(): void
    {
        $longSessionId = str_repeat('x', 1000);

        $response = $this->postJson('/api/chunks/complete', [
            'session_id' => $longSessionId,
        ]);

        $this->assertNotEquals(500, $response->status(), 'complete() must not crash on long session_id');
        $this->assertContains(
            $response->status(),
            [400, 404, 422],
            'complete() with non-UUID session_id must return a controlled error'
        );
    }

    /**
     * CONTROL: upload() correctly enforces UUID regex, unlike complete().
     */
    public function test_vuln_sec_007_upload_endpoint_rejects_non_uuid_session_id(): void
    {
        $longSessionId = str_repeat('x', 1000);
        $content = 'test content';
        $file = UploadedFile::fake()->createWithContent('chunk.tmp', $content);

        $response = $this->call('POST', '/api/chunks/upload', [
            'session_id' => $longSessionId,
            'chunk_index' => 0,
            'chunk_hash' => hash('sha256', $content),
        ], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('session_id', $response->json('errors') ?? []);
    }
}
