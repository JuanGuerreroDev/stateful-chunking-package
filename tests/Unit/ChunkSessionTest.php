<?php

declare(strict_types=1);

use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;

test('ChunkSession initializes with default timestamps and calculates non-expired state', function () {
    $now = time();
    $session = new ChunkSession(
        sessionId: SessionId::generate(),
        fileName: 'test.pdf',
        fileSize: 1048576,
        totalChunks: 4,
        totalHash: ChunkHash::fromString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
        fingerprint: 'fp-123'
    );

    expect($session->createdAt)->toBeGreaterThanOrEqual($now)
        ->and($session->expiresAt)->toBe($session->createdAt + 21600)
        ->and($session->isExpired())->toBeFalse()
        ->and($session->remainingTtl())->toBeGreaterThan(0)
        ->and($session->status)->toBe(SessionStatus::PENDING);

    $array = $session->toArray();
    expect($array['created_at'])->toBe($session->createdAt)
        ->and($array['expires_at'])->toBe($session->expiresAt)
        ->and($array['is_expired'])->toBeFalse()
        ->and($array['remaining_ttl'])->toBeGreaterThan(0);
});

test('ChunkSession detects expired state and clamps remaining TTL to zero', function () {
    $pastCreatedAt = time() - 3600;
    $pastExpiresAt = time() - 10;

    $session = new ChunkSession(
        sessionId: SessionId::generate(),
        fileName: 'expired.pdf',
        fileSize: 524288,
        totalChunks: 2,
        totalHash: ChunkHash::fromString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
        fingerprint: 'fp-expired',
        createdAt: $pastCreatedAt,
        expiresAt: $pastExpiresAt
    );

    expect($session->isExpired())->toBeTrue()
        ->and($session->remainingTtl())->toBe(0);

    $array = $session->toArray();
    expect($array['is_expired'])->toBeTrue()
        ->and($array['remaining_ttl'])->toBe(0);
});
