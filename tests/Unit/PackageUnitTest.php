<?php

use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkSize;
use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;

test('Package ChunkSize validates multiples of 256 KB', function () {
    $size = new ChunkSize(2097152); // 2 MB
    expect($size->value)->toBe(2097152);
});

test('Package SessionId generates non-empty string', function () {
    $session = SessionId::generate();
    expect($session->value)->not()->toBeEmpty();
});

test('Package ChunkHash formats hash correctly', function () {
    $hash = ChunkHash::fromString('  abc123DEF456  ');
    expect((string) $hash)->toBe('abc123def456');
});
