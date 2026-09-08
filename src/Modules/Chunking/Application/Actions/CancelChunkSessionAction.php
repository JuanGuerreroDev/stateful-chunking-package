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
            $this->storage->deleteTemporaryChunks($sessionId, $session->totalChunks);
            $this->repository->deleteSession($sessionId);
            ChunkSessionCancelled::dispatch($sessionId);
        }
    }
}
