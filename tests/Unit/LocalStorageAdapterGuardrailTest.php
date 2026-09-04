<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Juanoecr\StatefulChunking\Modules\Chunking\Infrastructure\Storage\LocalStorageAdapter;

test('LocalStorageAdapter stores chunk and validates checksum correctly', function () {
    Storage::fake('local');
    config()->set('stateful-chunking.storage_disk', 'local');
    config()->set('stateful-chunking.storage_path', 'uploads');

    $adapter = new LocalStorageAdapter();
    $content = 'Chunk test binary content';
    $hash = hash('sha256', $content);

    $path = $adapter->storeChunk('test-sess-1', 0, $content, $hash);

    expect($path)->toBe('chunks_temp/test-sess-1/chunk_0.tmp');
    Storage::disk('local')->assertExists($path);
});

test('LocalStorageAdapter throws exception on chunk hash mismatch', function () {
    Storage::fake('local');
    config()->set('stateful-chunking.storage_disk', 'local');

    $adapter = new LocalStorageAdapter();
    $content = 'Chunk content';
    $invalidHash = str_repeat('a', 64);

    expect(fn() => $adapter->storeChunk('test-sess-2', 0, $content, $invalidHash))
        ->toThrow(RuntimeException::class, 'Chunk 0 integrity check failed: SHA-256 hash mismatch.');
});

test('LocalStorageAdapter throws descriptive exception on missing chunk during reassembly', function () {
    Storage::fake('local');
    config()->set('stateful-chunking.storage_disk', 'local');

    $adapter = new LocalStorageAdapter();

    expect(fn() => $adapter->reassembleFile('non-existent-sess', 'file.bin', 2, 'dummy-hash'))
        ->toThrow(RuntimeException::class, 'Missing chunk 0 for reassembly.');
});
