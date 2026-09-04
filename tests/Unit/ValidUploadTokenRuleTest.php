<?php

declare(strict_types=1);

use Juanoecr\StatefulChunking\Rules\ValidUploadToken;
use Juanoecr\StatefulChunking\Core\Services\StatefulChunkingService;
use Illuminate\Support\Facades\Validator;

test('ValidUploadToken rule passes for a valid unexpired upload token', function () {
    $service = new StatefulChunkingService();
    $validToken = $service->generateToken(
        sessionId: '00000000-0000-4000-8000-000000000050',
        tempPath: 'uploads/temp/valid.mp4',
        fileName: 'valid.mp4',
        fileSize: 1048576,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ttl: 3600
    );

    $validator = Validator::make(
        ['upload_token' => $validToken],
        ['upload_token' => ['required', new ValidUploadToken($service)]]
    );

    expect($validator->passes())->toBeTrue()
        ->and($validator->errors()->isEmpty())->toBeTrue();
});

test('ValidUploadToken rule fails for non-string, empty or tampered tokens', function () {
    $rule = new ValidUploadToken();

    // 1. Non-string
    $validator1 = Validator::make(
        ['upload_token' => 12345],
        ['upload_token' => [new ValidUploadToken()]]
    );
    expect($validator1->fails())->toBeTrue();

    // 2. Empty string
    $validator2 = Validator::make(
        ['upload_token' => '   '],
        ['upload_token' => [new ValidUploadToken()]]
    );
    expect($validator2->fails())->toBeTrue();

    // 3. Tampered token
    $validator3 = Validator::make(
        ['upload_token' => 'tampered_invalid_token_123'],
        ['upload_token' => [new ValidUploadToken()]]
    );
    expect($validator3->fails())->toBeTrue();
    expect($validator3->errors()->first('upload_token'))->toContain('invalid or has been tampered with');
});

test('ValidUploadToken rule fails with specific message for expired tokens', function () {
    $service = new StatefulChunkingService();
    $expiredToken = $service->generateToken(
        sessionId: '00000000-0000-4000-8000-000000000060',
        tempPath: 'uploads/temp/expired.mp4',
        fileName: 'expired.mp4',
        fileSize: 1048576,
        hash: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ttl: -60
    );

    $validator = Validator::make(
        ['upload_token' => $expiredToken],
        ['upload_token' => [new ValidUploadToken($service)]]
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('upload_token'))->toContain('has expired');
});
