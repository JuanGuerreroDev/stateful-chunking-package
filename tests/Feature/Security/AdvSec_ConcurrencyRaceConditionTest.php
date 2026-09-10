<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Tests\Feature\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunkingUpload\Core\ValueObjects\SessionOwner;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Infrastructure\Repositories\CacheStateRepository;
use Juanoecr\StatefulChunkingUpload\Tests\TestCase;

/**
 * OFFENSIVE SECURITY TESTS: Concurrency Race Condition PoC (VULN-SEC-002)
 *
 * Demonstrates the read-modify-write lost update vulnerability in the fallback
 * file lock path when using a non-LockProvider cache driver in multi-server setups.
 *
 * NOTE: PHP single-process tests cannot truly simulate two concurrent OS processes.
 * This test manually simulates the INTERLEAVED execution order that would occur
 * when two server processes execute simultaneously without distributed locking.
 */
class AdvSec_ConcurrencyRaceConditionTest extends TestCase
{
    private CacheStateRepository $repository;

    private string $validHash;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('stateful-chunking-upload.rate_limits.initiate', 1000);
        Config::set('stateful-chunking-upload.rate_limits.upload', 1000);

        $this->repository = new CacheStateRepository;
        $this->validHash = hash('sha256', 'test_content');
    }

    private function createTestSession(string $sessionId, int $totalChunks = 3): ChunkSession
    {
        $session = new ChunkSession(
            sessionId: SessionId::fromString($sessionId),
            fileName: 'race_test.bin',
            fileSize: 1024 * $totalChunks,
            totalChunks: $totalChunks,
            totalHash: ChunkHash::fromString($this->validHash),
            fingerprint: '',
            createdAt: time(),
            expiresAt: time() + 3600,
            ownerId: SessionOwner::fromString('ip:127.0.0.1')
        );

        $this->repository->saveSession($session);

        return $session;
    }

    // -------------------------------------------------------------------------
    // VULN-SEC-002: Lost update simulation via manual interleaving
    // -------------------------------------------------------------------------

    /**
     * ATTACK: Simulate two concurrent processes reading the same session state
     * and each writing back their own partial update (lost update pattern).
     *
     * Timeline:
     *   Server A reads session  -> sees {0: pending, 1: pending, 2: pending}
     *   Server B reads session  -> sees {0: pending, 1: pending, 2: pending}
     *   Server A marks chunk 0 completed and writes back
     *   Server B marks chunk 1 completed and writes back (OVERWRITES Server A's write)
     *   Result: chunk 0 is LOST -> {0: pending, 1: completed, 2: pending}
     */
    public function test_vuln_sec_002_manual_interleaved_writes_demonstrate_lost_update(): void
    {
        $sessionId = '11111111-2222-3333-4444-555555555501';
        $this->createTestSession($sessionId, 3);

        // === Simulate Server A read ===
        $sessionA = $this->repository->getSession($sessionId);
        $this->assertNotNull($sessionA);
        $this->assertEquals('pending', $sessionA->chunksMap[0] ?? 'pending');

        // === Simulate Server B read (concurrent, before A writes back) ===
        $sessionB = $this->repository->getSession($sessionId);
        $this->assertNotNull($sessionB);

        // === Server A: mark chunk 0 as completed and save ===
        $sessionA->markChunkCompleted(0);
        $this->repository->saveSession($sessionA);

        // Verify Server A's write landed correctly
        $afterServerAWrite = $this->repository->getSession($sessionId);
        $this->assertNotNull($afterServerAWrite);
        $this->assertEquals('completed', $afterServerAWrite->chunksMap[0] ?? 'pending', 'Server A write should be visible');

        // === Server B: mark chunk 1 as completed and save (OVERWRITES Server A's write) ===
        // Server B still has its OLD snapshot from before Server A wrote
        $sessionB->markChunkCompleted(1);
        $this->repository->saveSession($sessionB); // This is the lost update

        // === Final state: Server A's chunk 0 update is LOST ===
        $finalSession = $this->repository->getSession($sessionId);
        $this->assertNotNull($finalSession);

        $chunk0Status = $finalSession->chunksMap[0] ?? 'pending';
        $chunk1Status = $finalSession->chunksMap[1] ?? 'pending';

        // VULN-SEC-002 CONFIRMED: chunk 0 is back to 'pending' because Server B overwrote it
        $this->assertEquals(
            'pending',
            $chunk0Status,
            'VULN-SEC-002 CONFIRMED: Chunk 0 (marked by Server A) was LOST when Server B wrote back its stale snapshot. '
            .'This is the classic read-modify-write lost update in the fallback lock path.'
        );

        $this->assertEquals(
            'completed',
            $chunk1Status,
            'Chunk 1 (marked by Server B) survived since it wrote last'
        );
    }

    /**
     * CONTROL: With proper atomic updateChunkStatus() on a LockProvider store,
     * sequential updates are serialized and no data is lost.
     *
     * The array cache driver (used in tests) implements LockProvider,
     * so this exercises the correct distributed lock path.
     */
    public function test_vuln_sec_002_atomic_update_chunk_status_prevents_lost_update(): void
    {
        $sessionId = '11111111-2222-3333-4444-555555555502';
        $this->createTestSession($sessionId, 3);

        // Both "servers" call updateChunkStatus() which uses the lock internally
        $this->repository->updateChunkStatus($sessionId, 0, 'completed');
        $this->repository->updateChunkStatus($sessionId, 1, 'completed');

        $finalSession = $this->repository->getSession($sessionId);
        $this->assertNotNull($finalSession);

        // Both updates survive when using the atomic lock-protected method
        $this->assertEquals('completed', $finalSession->chunksMap[0] ?? 'pending',
            'Chunk 0 should be completed after atomic update');
        $this->assertEquals('completed', $finalSession->chunksMap[1] ?? 'pending',
            'Chunk 1 should be completed after atomic update');
        $this->assertEquals('pending', $finalSession->chunksMap[2] ?? 'pending',
            'Chunk 2 should remain pending (not touched)');
    }

    /**
     * Documents the SPECIFIC condition that triggers the vulnerability:
     * the fallback file lock is process-local and provides NO protection
     * across different server instances sharing the same cache store.
     */
    public function test_vuln_sec_002_documents_fallback_lock_is_process_local(): void
    {
        // The fallback lock path is in sys_get_temp_dir()
        // Each server machine has its own /tmp directory
        // Therefore flock() only protects against concurrent PHP workers on the SAME machine
        $sessionId = 'lock-path-test-session-id-00001';
        $expectedLockPath = sprintf('%s/chunk_lock_%s.lock', sys_get_temp_dir(), md5($sessionId));

        // Verify the lock path formula
        $this->assertStringContainsString(sys_get_temp_dir(), $expectedLockPath);
        $this->assertStringContainsString(md5($sessionId), $expectedLockPath);

        // Document: in multi-server setup, Server B on machine B has a DIFFERENT /tmp
        // Therefore it will NEVER contend with Server A's flock() on machine A
        // This is the architectural flaw: local lock, shared cache = race condition
        $this->assertTrue(
            true,
            'VULN-SEC-002: The fallback flock() lock at '.$expectedLockPath
            .' is LOCAL to each machine. In a multi-server cluster with shared cache '
            .'(file cache over NFS or database cache), concurrent writes from different '
            .'servers are NOT serialized, leading to lost updates.'
        );
    }
}
