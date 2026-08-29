<?php

use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Entities\ChunkSession;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Enums\SessionStatus;
use StatefulChunking\LaravelPackage\Modules\Chunking\Infrastructure\Repositories\CacheStateRepository;

test('ServiceProvider resolves unified CacheStateRepository for all state drivers', function () {
    config()->set('stateful-chunking.driver', 'array');
    expect(app(StateRepositoryInterface::class))->toBeInstanceOf(CacheStateRepository::class);

    config()->set('stateful-chunking.driver', 'redis');
    expect(app(StateRepositoryInterface::class))->toBeInstanceOf(CacheStateRepository::class);
});

test('CacheStateRepository saves and retrieves session correctly', function () {
    config()->set('stateful-chunking.driver', 'array');
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
    config()->set('stateful-chunking.driver', 'array');
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
