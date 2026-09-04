<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-04 REGRESSION TEST: Session Ownership & IDOR Protection
 *
 * Security Invariants:
 * 1. Upload sessions must be bound to their creator (owner identity via authenticated user ID or client IP).
 * 2. An attacker knowing a valid sessionId MUST NOT be able to view session status (GET /status/{id}) -> HTTP 403.
 * 3. An attacker knowing a valid sessionId MUST NOT be able to cancel or purge another user's session (DELETE /cancel/{id}) -> HTTP 403.
 * 4. An attacker knowing a valid sessionId MUST NOT be able to complete another user's upload (POST /complete) -> HTTP 403.
 * 5. The legitimate owner MUST retain full control (status, upload, complete, cancel) -> HTTP 200.
 * 6. Fingerprint collision must not return another owner's session (VULN-07 prevention).
 */
class Vuln04SessionOwnershipRegressionTest extends TestCase
{
    private string $ownerIp = '198.51.100.10';
    private string $attackerIp = '203.0.113.99';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking.rate_limits.initiate', 1000);
        Config::set('stateful-chunking.rate_limits.upload', 1000);
        Config::set('stateful-chunking.rate_limits.status', 1000);
        Config::set('stateful-chunking.rate_limits.cancel', 1000);
        Config::set('stateful-chunking.rate_limits.complete', 1000);

        RateLimiter::clear('stateful-chunking-initiate');
        RateLimiter::clear('stateful-chunking-upload');
        RateLimiter::clear('stateful-chunking-status');
        RateLimiter::clear('stateful-chunking-cancel');
        RateLimiter::clear('stateful-chunking-complete');
    }

    /**
     * Helper to initiate a session on behalf of a specific IP address.
     */
    private function initiateAs(string $ip, string $fileName, string $content, ?string $fingerprint = null): string
    {
        $hash = hash('sha256', $content);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/chunks/initiate', [
            'file_name'    => $fileName,
            'file_size'    => strlen($content),
            'total_chunks' => 1,
            'total_hash'   => $hash,
            'fingerprint'  => $fingerprint ?? 'owner_fp_' . uniqid(),
        ]);

        $response->assertStatus(201);
        return (string) $response->json('data.session_id');
    }

    /**
     * REGRESSION 1: Attacker cannot inspect status of another user's session.
     */
    public function test_attacker_cannot_view_status_of_foreign_session(): void
    {
        $sessionId = $this->initiateAs($this->ownerIp, 'private_notes.txt', 'CONFIDENTIAL OWNER NOTES');

        // Attacker attempts to inspect session metadata via IDOR
        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->attackerIp])
            ->getJson("/api/chunks/status/{$sessionId}");

        $this->assertEquals(
            403,
            $response->status(),
            "Foreign status request should have been rejected with 403 Forbidden, got {$response->status()}"
        );
        $response->assertJson(['message' => 'Unauthorized action on chunk session.']);
    }

    /**
     * REGRESSION 2: Attacker cannot cancel or purge another user's session.
     */
    public function test_attacker_cannot_cancel_foreign_session(): void
    {
        $content = 'DATA IN TRANSIT MUST NOT BE CANCELLED';
        $sessionId = $this->initiateAs($this->ownerIp, 'active_upload.bin', $content);

        // Owner uploads chunk #0
        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);
        $uploadResponse = $this->withServerVariables(['REMOTE_ADDR' => $this->ownerIp])->call(
            'POST',
            '/api/chunks/upload',
            [
                'session_id'  => $sessionId,
                'chunk_index' => 0,
                'chunk_hash'  => hash('sha256', $content),
            ],
            [],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        );
        $uploadResponse->assertStatus(200);

        // Attacker attempts to cancel the session
        $cancelResponse = $this->withServerVariables(['REMOTE_ADDR' => $this->attackerIp])
            ->deleteJson("/api/chunks/cancel/{$sessionId}");

        $this->assertEquals(
            403,
            $cancelResponse->status(),
            "Foreign cancellation attempt must be rejected with 403 Forbidden, got {$cancelResponse->status()}"
        );

        // Verify session and its completed chunks are intact for the owner
        $statusCheck = $this->withServerVariables(['REMOTE_ADDR' => $this->ownerIp])
            ->getJson("/api/chunks/status/{$sessionId}");
        $statusCheck->assertStatus(200);
        $this->assertEquals('completed', $statusCheck->json('data.chunks_map.0'));
    }

    /**
     * REGRESSION 3: Attacker cannot complete or reassemble another user's session.
     */
    public function test_attacker_cannot_complete_foreign_session(): void
    {
        $content = 'LEGITIMATE CONTENT TO ASSEMBLE';
        $sessionId = $this->initiateAs($this->ownerIp, 'document.txt', $content);

        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);
        $this->withServerVariables(['REMOTE_ADDR' => $this->ownerIp])->call(
            'POST',
            '/api/chunks/upload',
            [
                'session_id'  => $sessionId,
                'chunk_index' => 0,
                'chunk_hash'  => hash('sha256', $content),
            ],
            [],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        );

        // Attacker attempts to complete and trigger file reassembly
        $completeResponse = $this->withServerVariables(['REMOTE_ADDR' => $this->attackerIp])
            ->postJson('/api/chunks/complete', [
                'session_id' => $sessionId,
            ]);

        $this->assertEquals(
            403,
            $completeResponse->status(),
            "Foreign completion attempt must be rejected with 403 Forbidden, got {$completeResponse->status()}"
        );
    }

    /**
     * REGRESSION 4: Legitimate owner retains full control of their session.
     */
    public function test_legitimate_owner_retains_full_lifecycle_control(): void
    {
        $content = 'LEGITIMATE COMPLETE LIFECYCLE';
        $sessionId = $this->initiateAs($this->ownerIp, 'owner_report.txt', $content);

        // 1. Status check by owner -> 200
        $status = $this->withServerVariables(['REMOTE_ADDR' => $this->ownerIp])
            ->getJson("/api/chunks/status/{$sessionId}");
        $status->assertStatus(200);

        // 2. Upload by owner -> 200
        $file = UploadedFile::fake()->createWithContent('chunk_0.tmp', $content);
        $upload = $this->withServerVariables(['REMOTE_ADDR' => $this->ownerIp])->call(
            'POST',
            '/api/chunks/upload',
            [
                'session_id'  => $sessionId,
                'chunk_index' => 0,
                'chunk_hash'  => hash('sha256', $content),
            ],
            [],
            ['file' => $file],
            ['HTTP_ACCEPT' => 'application/json']
        );
        $upload->assertStatus(200);

        // 3. Complete by owner -> 200
        $complete = $this->withServerVariables(['REMOTE_ADDR' => $this->ownerIp])
            ->postJson('/api/chunks/complete', [
                'session_id' => $sessionId,
            ]);
        $complete->assertStatus(200);
    }

    /**
     * REGRESSION 5: Fingerprint reuse does not hijack another owner's session.
     */
    public function test_fingerprint_reuse_does_not_hijack_foreign_session(): void
    {
        $sharedFingerprint = 'shared_fingerprint_' . uniqid();
        $content = 'SESSION ISOLATED CONTENT';

        // Owner creates session with fingerprint
        $ownerSessionId = $this->initiateAs($this->ownerIp, 'doc_a.txt', $content, $sharedFingerprint);

        // Attacker attempts initiate with SAME fingerprint from different IP
        $attackerResponse = $this->withServerVariables(['REMOTE_ADDR' => $this->attackerIp])->postJson('/api/chunks/initiate', [
            'file_name'    => 'doc_b.txt',
            'file_size'    => strlen($content),
            'total_chunks' => 1,
            'total_hash'   => hash('sha256', $content),
            'fingerprint'  => $sharedFingerprint,
        ]);

        $attackerResponse->assertStatus(201);
        $attackerSessionId = (string) $attackerResponse->json('data.session_id');

        // MUST NOT return the owner's session ID
        $this->assertNotEquals(
            $ownerSessionId,
            $attackerSessionId,
            'CRITICAL: Fingerprint collision allowed an attacker to hijack another user\'s session!'
        );
    }
}
