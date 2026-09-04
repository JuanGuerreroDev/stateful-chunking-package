<?php

declare(strict_types=1);

use Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService;
use Juanoecr\StatefulChunking\Facades\StatefulChunking;

test('StatefulChunkingService generates and resolves valid upload token', function () {
    $service = new StatefulChunkingService();

    $token = $service->generateToken(
        sessionId: '00000000-0000-4000-8000-000000000010',
        tempPath: 'uploads/temp/video.mp4',
        fileName: 'video.mp4',
        fileSize: 1048576,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        disk: 'local',
        ttl: 3600
    );

    expect($token)->toBeString()
        ->and(strlen($token))->toBeGreaterThan(50);

    $stagedFile = $service->resolveToken($token);

    expect($stagedFile->isValid())->toBeTrue()
        ->and($stagedFile->sessionId)->toBe('00000000-0000-4000-8000-000000000010')
        ->and($stagedFile->tempPath)->toBe('uploads/temp/video.mp4')
        ->and($stagedFile->fileName)->toBe('video.mp4')
        ->and($stagedFile->fileSize)->toBe(1048576)
        ->and($stagedFile->hash)->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')
        ->and($stagedFile->disk)->toBe('local');
});

test('StatefulChunking Facade resolves token seamlessly', function () {
    $token = StatefulChunking::generateToken(
        sessionId: '00000000-0000-4000-8000-000000000020',
        tempPath: 'uploads/temp/doc.pdf',
        fileName: 'doc.pdf',
        fileSize: 2048,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
    );

    $resolved = StatefulChunking::resolveToken($token);

    expect($resolved->isValid())->toBeTrue()
        ->and($resolved->sessionId)->toBe('00000000-0000-4000-8000-000000000020')
        ->and($resolved->fileName)->toBe('doc.pdf');
});

test('StatefulChunkingService handles tampered and malformed tokens safely without throwing uncaught exceptions', function () {
    $service = new StatefulChunkingService();

    $emptyResult = $service->resolveToken('');
    expect($emptyResult->isValid())->toBeFalse();

    $tamperedResult = $service->resolveToken('invalid-garbage-token-string');
    expect($tamperedResult->isValid())->toBeFalse();

    $corruptedPayload = $service->resolveToken('eyJpdiI6InRlc3QiLCJ2YWx1ZSI6ImJhZCJ9');
    expect($corruptedPayload->isValid())->toBeFalse();
});

test('StatefulChunkingService correctly marks expired tokens as invalid', function () {
    $service = new StatefulChunkingService();

    // Generate token with negative TTL (-10 seconds)
    $expiredToken = $service->generateToken(
        sessionId: '00000000-0000-4000-8000-000000000030',
        tempPath: 'uploads/temp/old.txt',
        fileName: 'old.txt',
        fileSize: 100,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        disk: 'local',
        ttl: -10
    );

    $resolved = $service->resolveToken($expiredToken);

    expect($resolved->isExpired())->toBeTrue()
        ->and($resolved->isValid())->toBeFalse();
});
