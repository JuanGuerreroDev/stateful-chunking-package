<?php

use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkSize;
use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;

test('Package ChunkSize validates multiples of 256 KB', function () {
    $size = new ChunkSize(2097152); // 2 MB
    expect($size->value)->toBe(2097152);
});

test('Package SessionId generates valid UUID v4', function () {
    $session = SessionId::generate();
    expect($session->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

test('Package ChunkHash formats valid SHA-256 hash correctly', function () {
    $validHash = 'E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855';
    $hash = ChunkHash::fromString('  ' . $validHash . '  ');
    expect((string) $hash)->toBe(strtolower($validHash));
});
