<?php

use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionOwner;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;

/**
 * Stage a chunk directory on the faked disk and backdate its files, so the sweep's
 * real mtime criterion is exercised rather than a mocked clock. ChunkSession works in
 * time(), not Carbon, so travel() would not move it.
 */
function stageChunkDirectory(string $sessionId, string $contents, int $ageSeconds): void
{
    $disk = Storage::disk('local');
    $path = sprintf('chunks_temp/%s/chunk_0.tmp', $sessionId);
    $disk->put($path, $contents);

    if ($ageSeconds > 0) {
        touch($disk->path($path), time() - $ageSeconds);
    }
}

function liveSession(string $fingerprint = ''): ChunkSession
{
    return new ChunkSession(
        sessionId: SessionId::generate(),
        fileName: 'in-flight.bin',
        fileSize: 1024,
        totalChunks: 4,
        totalHash: ChunkHash::fromString(hash('sha256', 'anything')),
        fingerprint: $fingerprint,
        status: SessionStatus::UPLOADING,
        ownerId: SessionOwner::fromString('user:1')
    );
}

test('the sweep collects an abandoned staging directory and reports what it reclaimed', function () {
    Storage::fake('local');

    $abandoned = SessionId::generate()->value;
    stageChunkDirectory($abandoned, str_repeat('X', 2048), 40000);

    // Nothing was ever written to the state store for it: the session is gone.
    expect(Storage::disk('local')->exists("chunks_temp/{$abandoned}/chunk_0.tmp"))->toBeTrue();

    $this->artisan('stateful-chunking:clear-stale')
        ->expectsOutput('1 abandoned staging directory (2.00 KB) collected. 0 skipped as still live.')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists("chunks_temp/{$abandoned}/chunk_0.tmp"))->toBeFalse();
});

test('the sweep never touches the chunks of a session that is still live', function () {
    Storage::fake('local');

    /** @var StateRepositoryInterface $repo */
    $repo = app(StateRepositoryInterface::class);

    // A slow upload: its first chunk is older than the TTL, but the session is alive.
    $live = liveSession();
    $repo->saveSession($live);
    stageChunkDirectory($live->sessionId->value, 'STILL UPLOADING', 40000);

    $this->artisan('stateful-chunking:clear-stale')
        ->expectsOutput('0 abandoned staging directories (0 B) collected. 1 skipped as still live.')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists("chunks_temp/{$live->sessionId->value}/chunk_0.tmp"))->toBeTrue();
});

test('a directory younger than the session TTL is not a candidate at all', function () {
    Storage::fake('local');

    $fresh = SessionId::generate()->value;
    stageChunkDirectory($fresh, 'JUST WRITTEN', 0);

    $this->artisan('stateful-chunking:clear-stale')
        ->expectsOutput('0 abandoned staging directories (0 B) collected. 0 skipped as still live.')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists("chunks_temp/{$fresh}/chunk_0.tmp"))->toBeTrue();
});

test('an empty staging directory is collected regardless of age', function () {
    Storage::fake('local');

    // Left behind when a session's chunks were removed but the directory was not:
    // it holds no bytes and no date to judge it by, so age cannot apply.
    $empty = SessionId::generate()->value;
    Storage::disk('local')->makeDirectory("chunks_temp/{$empty}");

    $this->artisan('stateful-chunking:clear-stale')
        ->expectsOutput('1 abandoned staging directory (0 B) collected. 0 skipped as still live.')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists("chunks_temp/{$empty}"))->toBeFalse();
});

test('dry-run reports the same collection without deleting anything', function () {
    Storage::fake('local');

    $abandoned = SessionId::generate()->value;
    stageChunkDirectory($abandoned, str_repeat('Y', 1024), 40000);

    $this->artisan('stateful-chunking:clear-stale', ['--dry-run' => true])
        ->expectsOutput('[dry-run] 1 abandoned staging directory (1.00 KB) would be collected. 0 skipped as still live.')
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists("chunks_temp/{$abandoned}/chunk_0.tmp"))->toBeTrue();
});

test('a storage adapter that cannot enumerate its staging area is told so, not reported as success', function () {
    Storage::fake('local');

    app()->bind(FileStorageInterface::class, OpaqueTestStorage::class);

    $this->artisan('stateful-chunking:clear-stale')
        ->expectsOutputToContain('cannot enumerate its staging area, so nothing was swept')
        ->assertExitCode(0);
});

test('a single session can still be cleared by id, with its chunks', function () {
    Storage::fake('local');

    /** @var StateRepositoryInterface $repo */
    $repo = app(StateRepositoryInterface::class);

    $session = liveSession('stale-fingerprint');
    $repo->saveSession($session);
    stageChunkDirectory($session->sessionId->value, 'TARGETED', 0);

    $this->artisan('stateful-chunking:clear-stale', ['--session' => $session->sessionId->value])
        ->expectsOutput(sprintf('Successfully cleared stale session [%s].', $session->sessionId->value))
        ->assertExitCode(0);

    expect($repo->getSession($session->sessionId->value))->toBeNull();
    expect(Storage::disk('local')->exists("chunks_temp/{$session->sessionId->value}/chunk_0.tmp"))->toBeFalse();
});

test('dry-run on a single session leaves it in place', function () {
    Storage::fake('local');

    /** @var StateRepositoryInterface $repo */
    $repo = app(StateRepositoryInterface::class);

    $session = liveSession();
    $repo->saveSession($session);
    stageChunkDirectory($session->sessionId->value, 'TARGETED', 0);

    $this->artisan('stateful-chunking:clear-stale', [
        '--session' => $session->sessionId->value,
        '--dry-run' => true,
    ])
        ->expectsOutput(sprintf('[dry-run] Would clear session [%s].', $session->sessionId->value))
        ->assertExitCode(0);

    expect($repo->getSession($session->sessionId->value))->not->toBeNull();
    expect(Storage::disk('local')->exists("chunks_temp/{$session->sessionId->value}/chunk_0.tmp"))->toBeTrue();
});

/**
 * A consumer's own adapter that satisfies the upload lifecycle but cannot list its
 * staging area — the exact case the segregated interface exists to keep working.
 */
class OpaqueTestStorage implements FileStorageInterface
{
    public function storeChunk(string $sessionId, int $chunkIndex, string $content, string $chunkHash): string
    {
        return 'noop';
    }

    public function reassembleFile(string $sessionId, string $fileName, int $totalChunks, string $expectedTotalHash): string
    {
        return 'noop';
    }

    public function deleteTemporaryChunks(string $sessionId, int $totalChunks): void {}

    public function deleteChunk(string $sessionId, int $chunkIndex): void {}
}
