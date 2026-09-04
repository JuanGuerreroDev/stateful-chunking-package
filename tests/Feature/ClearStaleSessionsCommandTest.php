<?php

use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;

test('ClearStaleSessionsCommand executes successfully', function () {
    $this->artisan('stateful-chunking:clear-stale')
        ->expectsOutput('Stateful Chunking garbage collection command executed successfully.')
        ->assertExitCode(0);
});

test('ClearStaleSessionsCommand clears specific session', function () {
    config()->set('stateful-chunking.driver', 'array');
    /** @var StateRepositoryInterface $repo */
    $repo = app(StateRepositoryInterface::class);

    $session = new ChunkSession(
        sessionId: SessionId::generate(),
        fileName: 'stale-file.log',
        fileSize: 1024,
        totalChunks: 1,
        totalHash: ChunkHash::fromString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'),
        fingerprint: 'stale-fingerprint',
        status: SessionStatus::PENDING,
        chunksMap: [0 => 'pending']
    );

    $repo->saveSession($session);

    $this->artisan('stateful-chunking:clear-stale', ['--session' => $session->sessionId->value])
        ->expectsOutput(sprintf('Successfully cleared stale session [%s].', $session->sessionId->value))
        ->assertExitCode(0);

    expect($repo->getSession($session->sessionId->value))->toBeNull();
});
