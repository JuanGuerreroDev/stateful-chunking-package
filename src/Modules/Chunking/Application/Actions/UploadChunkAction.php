<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions;

use StatefulChunking\LaravelPackage\Core\Contracts\FileStorageInterface;
use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\DTOs\UploadChunkDTO;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Entities\ChunkSession;
use RuntimeException;

final class UploadChunkAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository,
        private readonly FileStorageInterface $storage
    ) {}

    public function handle(UploadChunkDTO $dto): ChunkSession
    {
        $session = $this->repository->getSession($dto->sessionId->value);
        if (!$session) {
            throw new RuntimeException('Upload session not found or expired.');
        }

        if ($dto->chunkIndex < 0 || $dto->chunkIndex >= $session->totalChunks) {
            throw new RuntimeException(sprintf('Chunk index %d out of bounds.', $dto->chunkIndex));
        }

        // Store chunk payload & validate chunk SHA-256
        $this->storage->storeChunk(
            sessionId: $dto->sessionId->value,
            chunkIndex: $dto->chunkIndex,
            content: $dto->content,
            chunkHash: $dto->chunkHash->value
        );

        // Update state in Redis
        $this->repository->updateChunkStatus(
            sessionId: $dto->sessionId->value,
            chunkIndex: $dto->chunkIndex,
            status: 'completed'
        );

        $updatedSession = $this->repository->getSession($dto->sessionId->value) ?? $session;

        \StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Events\ChunkUploaded::dispatch(
            $updatedSession,
            $dto->chunkIndex,
            $dto->chunkHash->value
        );

        return $updatedSession;
    }
}
