<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions;

use StatefulChunking\LaravelPackage\Core\Contracts\FileStorageInterface;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;

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
            \StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Events\ChunkSessionCancelled::dispatch($sessionId);
        }
    }
}
