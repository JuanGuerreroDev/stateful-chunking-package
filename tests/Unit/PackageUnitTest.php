<?php

use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkSize;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;

test('Package ChunkSize validates multiples of 256 KB', function () {
    $size = new ChunkSize(2097152); // 2 MB
    expect($size->value)->toBe(2097152)
        ->and($size->toKb())->toBe(2048.0)
        ->and($size->toMb())->toBe(2.0)
        ->and((string) $size)->toBe('2097152');

    $sameSize = ChunkSize::fromInt(2097152);
    expect($size->equals($sameSize))->toBeTrue();

    $differentSize = new ChunkSize(262144);
    expect($size->equals($differentSize))->toBeFalse();
});

test('Package ChunkSize rejects invalid sizes', function () {
    expect(fn () => new ChunkSize(0))
        ->toThrow(InvalidArgumentException::class, 'ChunkSize must be a positive integer.');

    expect(fn () => new ChunkSize(100))
        ->toThrow(InvalidArgumentException::class, 'must be a multiple of 262144 bytes');
});

test('Package SessionId generates and compares valid UUID v4', function () {
    $session = SessionId::generate();
    expect($session->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i')
        ->and((string) $session)->toBe($session->value);

    $sameSession = SessionId::fromString($session->value);
    expect($session->equals($sameSession))->toBeTrue();
});

test('Package ChunkHash formats valid SHA-256 hash correctly', function () {
    $validHash = 'E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855';
    $hash = ChunkHash::fromString('  ' . $validHash . '  ');
    expect((string) $hash)->toBe(strtolower($validHash));

    $sameHash = ChunkHash::fromString($validHash);
    expect($hash->equals($sameHash))->toBeTrue();
});
