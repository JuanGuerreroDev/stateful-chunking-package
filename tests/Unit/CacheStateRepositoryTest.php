<?php

use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Repositories\CacheStateRepository;

test('ServiceProvider resolves unified CacheStateRepository for all state drivers', function () {
    config()->set('stateful-chunking.cache_store', 'array');
    expect(app(StateRepositoryInterface::class))->toBeInstanceOf(CacheStateRepository::class);

    config()->set('stateful-chunking.cache_store', 'redis');
    expect(app(StateRepositoryInterface::class))->toBeInstanceOf(CacheStateRepository::class);

    config()->set('stateful-chunking.driver', 'file');
    expect(app(StateRepositoryInterface::class))->toBeInstanceOf(CacheStateRepository::class);
});

test('CacheStateRepository saves and retrieves session correctly', function () {
    config()->set('stateful-chunking.cache_store', 'array');
    config()->set('stateful-chunking.session_ttl', 7200);
    /** @var CacheStateRepository $repo */
    $repo = app(CacheStateRepository::class);

    $session = new ChunkSession(
        sessionId: SessionId::generate(),
        fileName: 'test-file.zip',
        fileSize: 10485760,
        totalChunks: 5,
        totalHash: ChunkHash::fromString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
        fingerprint: 'test-fingerprint-123',
        status: SessionStatus::PENDING,
        chunksMap: [0 => 'completed', 1 => 'pending']
    );

    $repo->saveSession($session);

    $retrieved = $repo->getSession($session->sessionId->value);

    expect($retrieved)->not()->toBeNull();
    expect($retrieved->fileName)->toBe('test-file.zip');
    expect($retrieved->fileSize)->toBe(10485760);
    expect($retrieved->totalChunks)->toBe(5);
    expect($retrieved->fingerprint)->toBe('test-fingerprint-123');
    expect($retrieved->chunksMap)->toBe([0 => 'completed', 1 => 'pending']);

    $byFingerprint = $repo->findSessionByFingerprint('test-fingerprint-123');
    expect($byFingerprint)->not()->toBeNull();
    expect($byFingerprint->sessionId->value)->toBe($session->sessionId->value);
});

test('CacheStateRepository updates chunk status atomically and deletes session', function () {
    config()->set('stateful-chunking.cache_store', 'array');
    /** @var CacheStateRepository $repo */
    $repo = app(CacheStateRepository::class);

    $session = new ChunkSession(
        sessionId: SessionId::generate(),
        fileName: 'update-file.bin',
        fileSize: 2097152,
        totalChunks: 2,
        totalHash: ChunkHash::fromString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
        fingerprint: 'fingerprint-update-456',
        status: SessionStatus::UPLOADING,
        chunksMap: [0 => 'pending', 1 => 'pending']
    );

    $repo->saveSession($session);
    $repo->updateChunkStatus($session->sessionId->value, 0, 'completed');

    $updated = $repo->getSession($session->sessionId->value);
    expect($updated->chunksMap[0])->toBe('completed');

    $repo->deleteSession($session->sessionId->value);
    expect($repo->getSession($session->sessionId->value))->toBeNull();
});

test('CacheStateRepository falls back gracefully when cache store does not support atomic locks', function () {
    config()->set('cache.default', 'file');
    config()->set('stateful-chunking.cache_store', 'file');

    /** @var CacheStateRepository $repo */
    $repo = app(CacheStateRepository::class);

    $session = new ChunkSession(
        sessionId: SessionId::generate(),
        fileName: 'file-driver-test.txt',
        fileSize: 1024,
        totalChunks: 2,
        totalHash: ChunkHash::fromString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
        fingerprint: 'file-driver-fp',
        status: SessionStatus::UPLOADING,
        chunksMap: [0 => 'pending', 1 => 'pending']
    );

    $repo->saveSession($session);

    // This would throw BadMethodCallException without the LockProvider fallback
    $repo->updateChunkStatus($session->sessionId->value, 0, 'completed');

    $updated = $repo->getSession($session->sessionId->value);
    expect($updated)->not()->toBeNull();
    expect($updated->chunksMap[0])->toBe('completed');

    $repo->deleteSession($session->sessionId->value);
});

test('CacheStateRepository automatically purges expired session and returns null', function () {
    /** @var CacheStateRepository $repo */
    $repo = app(CacheStateRepository::class);

    $expiredSession = new ChunkSession(
        sessionId: SessionId::generate(),
        fileName: 'expired-session.txt',
        fileSize: 2048,
        totalChunks: 2,
        totalHash: ChunkHash::fromString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
        fingerprint: 'expired-fp-test',
        status: SessionStatus::UPLOADING,
        createdAt: time() - 3600,
        expiresAt: time() - 10
    );

    $repo->saveSession($expiredSession);

    // Retrieve should detect expiration, purge cache keys, and return null
    $retrieved = $repo->getSession($expiredSession->sessionId->value);
    expect($retrieved)->toBeNull();

    // Fingerprint search should also return null
    $byFp = $repo->findSessionByFingerprint('expired-fp-test');
    expect($byFp)->toBeNull();
});


