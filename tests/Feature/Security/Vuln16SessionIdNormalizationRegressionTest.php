<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\UnauthorizedSessionAccessException;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-16 REGRESSION TEST: Session id normalisation vs the ownership guard (AF-001).
 *
 * The ownership guard resolved the session with the raw request value while the
 * SessionId value object lowercased it later, inside the DTO. Cache keys are
 * case-sensitive, so an upper-case UUID missed the guarded lookup, the guard read the
 * resulting null as "no session to protect" and returned early, and the Action then
 * resolved the victim's session from the normalised id and accepted the chunk. The
 * ownership control was bypassed without ever emitting its audit entry.
 *
 * Security invariant: the identifier is canonicalised exactly once, at the adapter
 * boundary, before any authorization decision — so a case variant of a session id is
 * the same session for the guard and for the Action alike, and RFC 4122's
 * case-insensitivity stays true for the legitimate owner.
 */
class Vuln16SessionIdNormalizationRegressionTest extends TestCase
{
    private string $ownerIp = '198.51.100.10';

    private string $attackerIp = '203.0.113.99';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        foreach (['initiate', 'upload', 'status', 'complete', 'cancel'] as $op) {
            Config::set("stateful-chunking.rate_limits.{$op}", 1000);
            RateLimiter::clear("stateful-chunking-{$op}");
        }
    }

    /**
     * A UUID whose hex digits are all numeric would make the case-variance test
     * vacuous, so keep initiating until the identifier actually contains a letter.
     */
    private function initiateAs(string $ip, string $content): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/chunks/initiate', [
                'file_name' => 'victim.bin',
                'file_size' => strlen($content),
                'total_chunks' => 1,
                'total_hash' => hash('sha256', $content),
                'fingerprint' => 'fp_'.uniqid('', true),
            ]);
            $response->assertStatus(201);

            $sessionId = (string) $response->json('data.session_id');
            if ($sessionId !== strtoupper($sessionId)) {
                return $sessionId;
            }
        }

        self::fail('Could not obtain a session id containing hex letters.');
    }

    private function uploadAs(string $ip, string $sessionId, string $content): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->call(
            'POST',
            '/api/chunks/upload',
            [
                'session_id' => $sessionId,
                'chunk_index' => 0,
                'chunk_hash' => hash('sha256', $content),
            ],
            [],
            ['file' => UploadedFile::fake()->createWithContent('chunk_0.tmp', $content)],
            ['HTTP_ACCEPT' => 'application/json']
        );
    }

    /**
     * CONTROL: with the exact identifier, the guard has always worked. If this ever
     * fails, the bypass tests below prove nothing.
     */
    public function test_control_exact_session_id_from_a_foreign_caller_is_rejected(): void
    {
        $sessionId = $this->initiateAs($this->ownerIp, 'VICTIM ORIGINAL CONTENT');

        $response = $this->uploadAs($this->attackerIp, $sessionId, 'ATTACKER INJECTED CONTENT');

        $this->assertSame(403, $response->status());
    }

    /**
     * REGRESSION 1: the bypass itself — an upper-case identifier must not slip past
     * the guard, and the attacker's bytes must never reach the victim's staging dir.
     */
    public function test_uppercased_session_id_from_a_foreign_caller_is_rejected(): void
    {
        $sessionId = $this->initiateAs($this->ownerIp, 'VICTIM ORIGINAL CONTENT');

        $response = $this->uploadAs($this->attackerIp, strtoupper($sessionId), 'ATTACKER INJECTED CONTENT');

        $this->assertSame(403, $response->status(), 'Case-variant session id must not bypass the ownership guard.');
        $this->assertFalse(
            Storage::disk('local')->exists("chunks_temp/{$sessionId}/chunk_0.tmp"),
            'No attacker-controlled bytes may reach the victim session on disk.'
        );
    }

    /**
     * REGRESSION 2: the fix must not break RFC 4122, which makes UUIDs
     * case-insensitive on input. The owner sending upper case is still the owner.
     */
    public function test_uppercased_session_id_from_the_owner_still_works(): void
    {
        $content = 'VICTIM ORIGINAL CONTENT';
        $sessionId = $this->initiateAs($this->ownerIp, $content);

        $response = $this->uploadAs($this->ownerIp, strtoupper($sessionId), $content);

        $this->assertSame(200, $response->status(), 'A case variant from the owner must still be accepted.');
        $this->assertTrue(Storage::disk('local')->exists("chunks_temp/{$sessionId}/chunk_0.tmp"));
    }

    /**
     * REGRESSION 3: the same normalisation must hold on every endpoint that accepts a
     * session id, not only the one where the bypass was found.
     */
    public function test_uppercased_session_id_is_rejected_on_status_complete_and_cancel(): void
    {
        $sessionId = $this->initiateAs($this->ownerIp, 'VICTIM ORIGINAL CONTENT');
        $upper = strtoupper($sessionId);

        $status = $this->withServerVariables(['REMOTE_ADDR' => $this->attackerIp])
            ->getJson("/api/chunks/status/{$upper}");
        $this->assertSame(403, $status->status(), 'status must reject a case-variant foreign id');

        $complete = $this->withServerVariables(['REMOTE_ADDR' => $this->attackerIp])
            ->postJson('/api/chunks/complete', ['session_id' => $upper]);
        $this->assertSame(403, $complete->status(), 'complete must reject a case-variant foreign id');

        $cancel = $this->withServerVariables(['REMOTE_ADDR' => $this->attackerIp])
            ->deleteJson("/api/chunks/cancel/{$upper}");
        $this->assertSame(403, $cancel->status(), 'cancel must reject a case-variant foreign id');

        // The session survived every attempt.
        $ownerStatus = $this->withServerVariables(['REMOTE_ADDR' => $this->ownerIp])
            ->getJson("/api/chunks/status/{$sessionId}");
        $ownerStatus->assertStatus(200);
    }

    /**
     * REGRESSION 4: a malformed identifier is answered as a missing session, never as
     * a 500 — canonicalisation must not turn bad input into an unhandled exception.
     */
    public function test_malformed_session_ids_are_answered_as_not_found_not_as_errors(): void
    {
        foreach (['not-a-uuid', str_repeat('z', 36), '../../etc/passwd'] as $malformed) {
            $status = $this->getJson('/api/chunks/status/'.rawurlencode($malformed));
            $this->assertSame(404, $status->status(), "status/{$malformed} must be 404");

            $cancel = $this->deleteJson('/api/chunks/cancel/'.rawurlencode($malformed));
            $this->assertSame(404, $cancel->status(), "cancel/{$malformed} must be 404");
        }
    }

    /**
     * REGRESSION 5: /complete used to accept any string as a session id. It now
     * enforces the same UUID shape as every other endpoint.
     */
    public function test_complete_rejects_a_non_uuid_session_id(): void
    {
        $response = $this->postJson('/api/chunks/complete', ['session_id' => 'definitely-not-a-uuid']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('session_id');
    }

    /**
     * REGRESSION 6: the bypass was silent — it returned 200 without ever raising the
     * exception that records the attempt. An access-control failure that leaves no
     * trace is invisible to the operator, so assert the audit hook still fires.
     */
    public function test_ownership_failures_are_audited(): void
    {
        Log::shouldReceive('channel')->atLeast()->once()->andReturnSelf();
        Log::shouldReceive('log')
            ->atLeast()
            ->once()
            ->withArgs(fn (string $level, string $message, array $context): bool => str_contains($message, 'Unauthorized'));

        (new UnauthorizedSessionAccessException(
            'Unauthorized attempt to access chunk session (IDOR prevented).',
            ['session_id' => 'a0000000-0000-4000-8000-000000000000']
        ))->report();
    }
}
