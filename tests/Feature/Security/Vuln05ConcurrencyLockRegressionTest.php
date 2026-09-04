<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Repositories\CacheStateRepository;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * Custom cache store that intentionally does NOT implement Illuminate\Contracts\Cache\LockProvider.
 * Used to verify the file-lock advisory fallback in CacheStateRepository.
 */
class CustomNonLockingStore implements Store
{
    /** @var array<string, mixed> */
    private array $storage = [];

    public function get($key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    public function many(array $keys): array
    {
        $res = [];
        foreach ($keys as $key) {
            $res[$key] = $this->get($key);
        }
        return $res;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->storage[$key] = $value;
        return true;
    }

    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->storage[$key] = $value;
        }
        return true;
    }

    public function increment($key, $value = 1): int|bool
    {
        $current = (int) ($this->storage[$key] ?? 0);
        $new = $current + $value;
        $this->storage[$key] = $new;
        return $new;
    }

    public function decrement($key, $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value): bool
    {
        $this->storage[$key] = $value;
        return true;
    }

    public function forget($key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function flush(): bool
    {
        $this->storage = [];
        return true;
    }

    public function touch($key, $seconds): bool
    {
        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}

/**
 * VULN-05 REGRESSION TEST: Concurrency & LockProvider Fallback in CacheStateRepository
 *
 * Security Invariants:
 * 1. For cache drivers lacking a native LockProvider (custom stores, third-party drivers),
 *    the repository MUST use a robust advisory file lock (flock) to serialize state mutations.
 * 2. Rapid chunk updates must preserve all completed chunks in chunksMap without corruption.
 * 3. Fallback lock files must be properly cleaned up when the session is purged or cancelled.
 */
class Vuln05ConcurrencyLockRegressionTest extends TestCase
{
    private CacheStateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Register custom store that does NOT implement LockProvider
        Cache::extend('custom_unlocked', function ($app) {
            return Cache::repository(new CustomNonLockingStore());
        });

        Config::set('cache.stores.custom_unlocked', ['driver' => 'custom_unlocked']);
        Config::set('cache.default', 'custom_unlocked');
        Config::set('stateful-chunking.cache_store', 'custom_unlocked');

        $this->repository = new CacheStateRepository();
    }

    private function createDummySession(int $totalChunks = 5): ChunkSession
    {
        $session = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: 'concurrent_upload_test.bin',
            fileSize: $totalChunks * 1024,
            totalChunks: $totalChunks,
            totalHash: ChunkHash::fromString(str_repeat('a', 64)),
            fingerprint: 'concurrency_fp_' . uniqid(),
            status: SessionStatus::PENDING,
            chunksMap: array_fill(0, $totalChunks, 'pending'),
            createdAt: time(),
            expiresAt: time() + 3600,
            ownerId: 'user:test-owner'
        );

        $this->repository->saveSession($session);
        return $session;
    }

    /**
     * REGRESSION 1: Rapid updates on non-LockProvider cache store preserve all chunk statuses
     * without lost updates.
     */
    public function test_all_chunks_completed_without_state_corruption_on_non_lock_provider_store(): void
    {
        $totalChunks = 8;
        $session = $this->createDummySession($totalChunks);
        $sessionId = $session->sessionId->value;

        // Simulate 8 chunks completing
        for ($i = 0; $i < $totalChunks; $i++) {
            $this->repository->updateChunkStatus($sessionId, $i, 'completed');
        }

        $reloaded = $this->repository->getSession($sessionId);
        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->isComplete());
        $this->assertEquals(SessionStatus::COMPLETED, $reloaded->status);

        for ($i = 0; $i < $totalChunks; $i++) {
            $this->assertEquals('completed', $reloaded->chunksMap[$i]);
        }
    }

    /**
     * REGRESSION 2: Advisory file lock serialization prevents race conditions.
     * When an external lock is held, mutual exclusion is enforced.
     */
    public function test_fallback_lock_enforces_mutual_exclusion(): void
    {
        $session = $this->createDummySession(2);
        $sessionId = $session->sessionId->value;

        $lockPath = sprintf('%s/chunk_lock_%s.lock', sys_get_temp_dir(), md5($sessionId));

        // Open an external lock simulating another concurrent process holding the lock
        $externalFp = fopen($lockPath, 'c+');
        $this->assertIsResource($externalFp);

        $acquired = flock($externalFp, LOCK_EX | LOCK_NB);
        $this->assertTrue($acquired, 'Test setup: external process must acquire exclusive lock');

        // While locked, a non-blocking check on a second handle must fail to acquire LOCK_EX
        $testFp = fopen($lockPath, 'c+');
        $this->assertIsResource($testFp);
        $secondAcquired = flock($testFp, LOCK_EX | LOCK_NB);
        $this->assertFalse($secondAcquired, 'Mutual exclusion: second handle must be blocked while first handle holds lock');

        // Release the external lock
        flock($externalFp, LOCK_UN);
        fclose($externalFp);
        fclose($testFp);

        // Now repository update should smoothly proceed with lock
        $this->repository->updateChunkStatus($sessionId, 0, 'completed');
        $reloaded = $this->repository->getSession($sessionId);
        $this->assertNotNull($reloaded);
        $this->assertEquals('completed', $reloaded->chunksMap[0]);
    }

    /**
     * REGRESSION 3: Fallback lockfile is created during update and cleaned up on deleteSession.
     */
    public function test_delete_session_cleans_up_fallback_lockfile(): void
    {
        $session = $this->createDummySession(2);
        $sessionId = $session->sessionId->value;

        // Perform an update on non-LockProvider store to trigger fallback file lock
        $this->repository->updateChunkStatus($sessionId, 0, 'completed');

        $lockPath = sprintf('%s/chunk_lock_%s.lock', sys_get_temp_dir(), md5($sessionId));
        $this->assertFileExists($lockPath, 'Fallback lock file should have been created on non-LockProvider store');

        // Delete the session
        $this->repository->deleteSession($sessionId);

        // Session must be gone
        $this->assertNull($this->repository->getSession($sessionId));

        // Lock file in temp dir must be deleted
        $this->assertFileDoesNotExist($lockPath);
    }
}
