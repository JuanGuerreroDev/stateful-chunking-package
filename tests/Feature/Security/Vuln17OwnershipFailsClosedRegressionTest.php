<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-17 REGRESSION TEST: ownership must fail closed (AF-006).
 *
 * The guard read `$session->ownerId !== null && $session->ownerId !== $attemptedBy`,
 * so a session with no owner matched nobody and was therefore allowed for everybody.
 * Fingerprint reuse carried the same defect. Sessions created through HTTP always get
 * an owner, but the package publishes `StateRepositoryInterface` as an extension
 * point, and a host application persisting a session without one turned it into a
 * public object: readable, completable and cancellable by any caller who knew its id.
 *
 * Security invariant: an unowned session belongs to nobody, not to everybody. Access
 * control denies by default.
 */
class Vuln17OwnershipFailsClosedRegressionTest extends TestCase
{
    private const CONTENT = 'UNOWNED SESSION CONTENT';

    private string $callerIp = '203.0.113.99';

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
     * Persist a session with no owner, the way a host application extending the
     * package through the published repository contract could.
     */
    private function persistUnownedSession(string $fingerprint = ''): string
    {
        $session = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: 'unowned.bin',
            fileSize: strlen(self::CONTENT),
            totalChunks: 1,
            totalHash: ChunkHash::fromString(hash('sha256', self::CONTENT)),
            fingerprint: $fingerprint,
        );

        $this->app->make(StateRepositoryInterface::class)->saveSession($session);

        return $session->sessionId->value;
    }

    public function test_status_of_an_unowned_session_is_denied(): void
    {
        $sessionId = $this->persistUnownedSession();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->callerIp])
            ->getJson("/api/chunks/status/{$sessionId}");

        $this->assertSame(403, $response->status(), 'An unowned session must not be readable by an arbitrary caller.');
    }

    public function test_upload_into_an_unowned_session_is_denied(): void
    {
        $sessionId = $this->persistUnownedSession();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->callerIp])->call(
            'POST',
            '/api/chunks/upload',
            [
                'session_id' => $sessionId,
                'chunk_index' => 0,
                'chunk_hash' => hash('sha256', self::CONTENT),
            ],
            [],
            ['file' => UploadedFile::fake()->createWithContent('chunk_0.tmp', self::CONTENT)],
            ['HTTP_ACCEPT' => 'application/json']
        );

        $this->assertSame(403, $response->status());
        $this->assertFalse(Storage::disk('local')->exists("chunks_temp/{$sessionId}/chunk_0.tmp"));
    }

    public function test_complete_of_an_unowned_session_is_denied(): void
    {
        $sessionId = $this->persistUnownedSession();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->callerIp])
            ->postJson('/api/chunks/complete', ['session_id' => $sessionId]);

        $this->assertSame(403, $response->status());
    }

    public function test_cancel_of_an_unowned_session_is_denied_and_leaves_it_intact(): void
    {
        $sessionId = $this->persistUnownedSession();

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->callerIp])
            ->deleteJson("/api/chunks/cancel/{$sessionId}");

        $this->assertSame(403, $response->status());
        $this->assertNotNull(
            $this->app->make(StateRepositoryInterface::class)->getSession($sessionId),
            'A denied cancellation must not destroy the session.'
        );
    }

    /**
     * The fingerprint is a value the client chooses freely. Reusing an unowned session
     * on a fingerprint match would let a caller be handed a session simply by guessing
     * the label another party used for it.
     */
    public function test_fingerprint_reuse_does_not_hand_over_an_unowned_session(): void
    {
        $fingerprint = 'shared-client-fingerprint';
        $unownedId = $this->persistUnownedSession($fingerprint);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->callerIp])->postJson('/api/chunks/initiate', [
            'file_name' => 'mine.bin',
            'file_size' => strlen(self::CONTENT),
            'total_chunks' => 1,
            'total_hash' => hash('sha256', self::CONTENT),
            'fingerprint' => $fingerprint,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame(
            $unownedId,
            (string) $response->json('data.session_id'),
            'A fingerprint match must not surrender an unowned session to whoever asks.'
        );
    }
}
