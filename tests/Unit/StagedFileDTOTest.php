<?php

declare(strict_types=1);

use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\StagedFileDTO;
use Illuminate\Support\Facades\Storage;

test('StagedFileDTO instantiates correctly and validates validity and expiration', function () {
    $dto = new StagedFileDTO(
        sessionId: '00000000-0000-4000-8000-000000000001',
        tempPath: 'uploads/temp/test.txt',
        fileName: 'test.txt',
        fileSize: 1024,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        disk: 'local',
        expiresAt: time() + 3600,
        isValid: true
    );

    expect($dto->sessionId)->toBe('00000000-0000-4000-8000-000000000001')
        ->and($dto->tempPath)->toBe('uploads/temp/test.txt')
        ->and($dto->fileName)->toBe('test.txt')
        ->and($dto->fileSize)->toBe(1024)
        ->and($dto->hash)->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
        ->and($dto->disk)->toBe('local')
        ->and($dto->isExpired())->toBeFalse()
        ->and($dto->isValid())->toBeTrue();

    $array = $dto->toArray();
    expect($array['session_id'])->toBe('00000000-0000-4000-8000-000000000001')
        ->and($array['is_valid'])->toBeTrue();

    $rehydrated = StagedFileDTO::fromArray($array);
    expect($rehydrated->sessionId)->toBe($dto->sessionId)
        ->and($rehydrated->tempPath)->toBe($dto->tempPath)
        ->and($rehydrated->isValid())->toBeTrue();
});

test('StagedFileDTO detects expired tokens', function () {
    $dto = new StagedFileDTO(
        sessionId: '00000000-0000-4000-8000-000000000002',
        tempPath: 'uploads/temp/expired.txt',
        fileName: 'expired.txt',
        fileSize: 512,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        disk: 'local',
        expiresAt: time() - 60, // expired 1 minute ago
        isValid: true
    );

    expect($dto->isExpired())->toBeTrue()
        ->and($dto->isValid())->toBeFalse();
});

test('StagedFileDTO returns mime type via storage helper', function () {
    Storage::fake('local');
    Storage::disk('local')->put('uploads/temp/sample.json', json_encode(['foo' => 'bar']));

    $dto = new StagedFileDTO(
        sessionId: '00000000-0000-4000-8000-000000000003',
        tempPath: 'uploads/temp/sample.json',
        fileName: 'sample.json',
        fileSize: 15,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        disk: 'local',
        expiresAt: time() + 3600
    );

    expect($dto->mimeType())->toBeIn(['application/json', 'text/plain']);
});
