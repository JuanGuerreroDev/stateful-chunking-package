<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Tests\Feature\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkSessionExpired;
use Juanoecr\StatefulChunking\Tests\TestCase;

/**
 * VULN-18 REGRESSION TEST: Permanent disk leak through uncollected staging chunks
 *
 * AF-003. Two facts compounded into an unbounded disk leak:
 *
 * 1. `CacheStateRepository::deleteSession()` clears the cache entry, the fingerprint
 *    mapping and the fallback lock, but never `chunks_temp/<sessionId>/`. The lazy
 *    expiry branch of `getSession()` calls exactly that method, so an abandoned
 *    session lost its state and left its bytes behind forever.
 * 2. `stateful-chunking:clear-stale` collected nothing without `--session`. It printed
 *    "executed successfully" and returned SUCCESS, and the README instructed operators
 *    to schedule it hourly. That is worse than having no collector: it manufactures the
 *    belief that one exists.
 *
 * Security Invariants:
 * 1. Detecting an expired session MUST also free the staging directory it described.
 * 2. The scheduled sweep MUST collect staging directories whose session state is gone,
 *    and MUST report a real count rather than an unconditional success message.
 * 3. The sweep MUST NOT collect the chunks of a session that is still live, however
 *    long ago its last chunk was written.
 */
class Vuln18OrphanChunkGarbageCollectionRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Force a live cache entry to describe an already-expired session.
     *
     * saveSession() clamps its cache TTL to the session's remaining life, so it cannot
     * persist a session that is already dead. Writing the payload directly reproduces
     * what every cache driver does in practice: the entry outlives the session's own
     * expires_at until something reads it. That gap is the whole reason lazy expiry
     * exists, and it is where the chunks used to be stranded.
     */
    private function backdateSession(string $sessionId): void
    {
        $store = Cache::store(config('stateful-chunking.cache_store'));
        $key = 'chunk_session:'.$sessionId;

        /** @var array<string, mixed> $payload */
        $payload = $store->get($key);
        $payload['expires_at'] = time() - 60;

        $store->put($key, $payload, 600);
    }

    private function stageAbandonedDirectory(string $sessionId, string $body, int $ageSeconds): string
    {
        $disk = Storage::disk('local');
        $path = sprintf('chunks_temp/%s/chunk_0.tmp', $sessionId);
        $disk->put($path, $body);
        touch($disk->path($path), time() - $ageSeconds);

        return $path;
    }

    /**
     * REGRESSION 1: the read that detects expiry also frees the disk.
     *
     * This is the audit's proof-of-concept inverted. It used to end with the .tmp file
     * still on disk after /status had already returned 404.
     */
    public function test_expired_session_chunks_are_purged_on_the_read_that_detects_expiry(): void
    {
        Event::fake([ChunkSessionExpired::class]);

        $body = 'CHUNK THAT MUST NOT OUTLIVE ITS SESSION';
        $full = $body.'TAIL';

        $initiate = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'abandoned.txt',
            'file_size' => strlen($full),
            'total_chunks' => 2,
            'total_hash' => hash('sha256', $full),
            'fingerprint' => 'vuln18-abandoned',
        ]);
        $initiate->assertStatus(201);
        $sessionId = (string) $initiate->json('data.session_id');

        $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => hash('sha256', $body),
        ], [], ['file' => UploadedFile::fake()->createWithContent('c0.tmp', $body)])->assertStatus(200);

        $chunkPath = sprintf('chunks_temp/%s/chunk_0.tmp', $sessionId);
        Storage::disk('local')->assertExists($chunkPath);

        // The user walks away. The session outlives its TTL while its cache entry lives on.
        $this->backdateSession($sessionId);

        // Any read is enough — here, the client polling its own progress.
        $this->getJson("/api/chunks/status/{$sessionId}")->assertStatus(404);

        Event::assertDispatched(ChunkSessionExpired::class, function (ChunkSessionExpired $event) use ($sessionId): bool {
            return $event->sessionId === $sessionId && $event->totalChunks === 2;
        });
    }

    /**
     * REGRESSION 1b: the listener wired in the composition root actually deletes.
     *
     * Kept separate from the dispatch assertion on purpose: faking the event proves the
     * announcement, and only a real dispatch proves the disk is freed. A test that only
     * did the former would pass with the listener unregistered.
     */
    public function test_the_registered_listener_removes_the_directory_on_a_real_expiry(): void
    {
        $body = 'REAL PURGE PATH';
        $full = $body.'TAIL';

        $initiate = $this->postJson('/api/chunks/initiate', [
            'file_name' => 'abandoned.txt',
            'file_size' => strlen($full),
            'total_chunks' => 2,
            'total_hash' => hash('sha256', $full),
            'fingerprint' => 'vuln18-real-purge',
        ]);
        $sessionId = (string) $initiate->json('data.session_id');

        $this->call('POST', '/api/chunks/upload', [
            'session_id' => $sessionId,
            'chunk_index' => 0,
            'chunk_hash' => hash('sha256', $body),
        ], [], ['file' => UploadedFile::fake()->createWithContent('c0.tmp', $body)])->assertStatus(200);

        $chunkPath = sprintf('chunks_temp/%s/chunk_0.tmp', $sessionId);
        Storage::disk('local')->assertExists($chunkPath);

        $this->backdateSession($sessionId);
        $this->getJson("/api/chunks/status/{$sessionId}")->assertStatus(404);

        Storage::disk('local')->assertMissing($chunkPath);
    }

    /**
     * REGRESSION 2: the scheduled sweep collects what nobody will ever read again.
     *
     * The early purge only fires if something touches the dead session. A session whose
     * client never comes back is never read, so the sweep is the only thing standing
     * between it and permanent retention.
     */
    public function test_the_scheduled_sweep_collects_a_directory_whose_session_is_gone(): void
    {
        $orphan = SessionId::generate()->value;
        $path = $this->stageAbandonedDirectory($orphan, str_repeat('Z', 4096), 40000);

        Storage::disk('local')->assertExists($path);

        $this->artisan('stateful-chunking:clear-stale')
            ->expectsOutput('1 abandoned staging directory (4.00 KB) collected. 0 skipped as still live.')
            ->assertExitCode(0);

        Storage::disk('local')->assertMissing($path);
    }

    /**
     * REGRESSION 3: the guard. Age alone must never be enough to collect.
     *
     * A large file uploaded over a slow link can leave its first chunks untouched for
     * longer than the TTL while the session is perfectly healthy. Collecting on mtime
     * alone would delete the work of the very users the package exists to serve.
     */
    public function test_the_sweep_refuses_to_collect_chunks_of_a_session_that_is_still_live(): void
    {
        /** @var StateRepositoryInterface $repository */
        $repository = app(StateRepositoryInterface::class);

        $session = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: 'slow-upload.iso',
            fileSize: 4096,
            totalChunks: 8,
            totalHash: ChunkHash::fromString(hash('sha256', 'slow')),
            fingerprint: 'vuln18-slow',
            status: SessionStatus::UPLOADING,
            ownerId: 'user:7'
        );
        $repository->saveSession($session);

        $path = $this->stageAbandonedDirectory($session->sessionId->value, 'OLD BUT ALIVE', 40000);

        $this->artisan('stateful-chunking:clear-stale')
            ->expectsOutput('0 abandoned staging directories (0 B) collected. 1 skipped as still live.')
            ->assertExitCode(0);

        Storage::disk('local')->assertExists($path);
    }

    /**
     * REGRESSION 4: purging is idempotent, so two requests detecting the same expiry
     * cannot conflict, and a sweep after an early purge is a no-op rather than an error.
     */
    public function test_purging_the_same_session_twice_is_harmless(): void
    {
        $orphan = SessionId::generate()->value;
        $this->stageAbandonedDirectory($orphan, 'ONCE', 40000);

        $this->artisan('stateful-chunking:clear-stale')->assertExitCode(0);
        $this->artisan('stateful-chunking:clear-stale')
            ->expectsOutput('0 abandoned staging directories (0 B) collected. 0 skipped as still live.')
            ->assertExitCode(0);
    }
}
