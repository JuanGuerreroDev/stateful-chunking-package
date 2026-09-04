<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunking\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\UploadChunkDTO;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
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

        // Idempotency: if chunk is already marked completed, validate integrity and return existing session
        if (($session->chunksMap[$dto->chunkIndex] ?? null) === 'completed') {
            $computedHash = hash('sha256', $dto->content);
            if (!hash_equals(strtolower($dto->chunkHash->value), strtolower($computedHash))) {
                throw new RuntimeException(
                    sprintf('Chunk %d integrity check failed: SHA-256 hash mismatch.', $dto->chunkIndex)
                );
            }

            return $session;
        }

        // Store chunk payload & validate chunk SHA-256
        $this->storage->storeChunk(
            sessionId: $dto->sessionId->value,
            chunkIndex: $dto->chunkIndex,
            content: $dto->content,
            chunkHash: $dto->chunkHash->value
        );

        // Update state in cache store
        $this->repository->updateChunkStatus(
            sessionId: $dto->sessionId->value,
            chunkIndex: $dto->chunkIndex,
            status: 'completed'
        );

        $updatedSession = $this->repository->getSession($dto->sessionId->value) ?? $session;

        \Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkUploaded::dispatch(
            $updatedSession,
            $dto->chunkIndex,
            $dto->chunkHash->value
        );

        return $updatedSession;
    }
}
