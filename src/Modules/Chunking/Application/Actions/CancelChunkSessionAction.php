<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunking\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkSessionCancelled;

final class CancelChunkSessionAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository,
        private readonly FileStorageInterface $storage
    ) {}

    public function handle(string $sessionId): void
    {
        $session = $this->repository->getSession($sessionId);
        if ($session) {
            // Derive every side effect from the resolved session's own identifier rather
            // than from the string we were called with, so a caller that skipped
            // canonicalisation cannot steer a filesystem path.
            $resolvedId = $session->sessionId->value;

            $this->storage->deleteTemporaryChunks($resolvedId, $session->totalChunks);
            $this->repository->deleteSession($resolvedId);
            ChunkSessionCancelled::dispatch($resolvedId);
        }
    }
}
