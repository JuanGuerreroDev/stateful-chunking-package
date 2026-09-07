<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunking\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\UploadChunkDTO;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkUploaded;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\ChunkIntegrityException;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\SessionNotFoundException;

final class UploadChunkAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository,
        private readonly FileStorageInterface $storage
    ) {}

    public function handle(UploadChunkDTO $dto): ChunkSession
    {
        $session = $this->repository->getSession($dto->sessionId->value);
        if (! $session) {
            throw new SessionNotFoundException(
                'Chunk uploaded to unknown or expired session.',
                ['session_id' => $dto->sessionId->value, 'chunk_index' => $dto->chunkIndex]
            );
        }

        // Aggregate invariant: the chunk index must be within the session's bounds.
        $session->assertChunkIndexWithinBounds($dto->chunkIndex);

        // Idempotency: if chunk is already marked completed, validate integrity and return existing session
        if (($session->chunksMap[$dto->chunkIndex] ?? null) === 'completed') {
            $computedHash = hash('sha256', $dto->content);
            if (! hash_equals(strtolower($dto->chunkHash->value), strtolower($computedHash))) {
                throw new ChunkIntegrityException(
                    sprintf('Chunk %d integrity check failed on idempotent re-upload.', $dto->chunkIndex),
                    ['session_id' => $dto->sessionId->value, 'chunk_index' => $dto->chunkIndex]
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

        try {
            // Update state in cache store
            $this->repository->updateChunkStatus(
                sessionId: $dto->sessionId->value,
                chunkIndex: $dto->chunkIndex,
                status: 'completed'
            );
        } catch (\Throwable $e) {
            // Rollback: clean up written chunk file so it doesn't stay orphaned on disk
            $this->storage->deleteChunk(
                sessionId: $dto->sessionId->value,
                chunkIndex: $dto->chunkIndex
            );

            throw $e;
        }

        $updatedSession = $this->repository->getSession($dto->sessionId->value) ?? $session;

        ChunkUploaded::dispatch(
            $updatedSession,
            $dto->chunkIndex,
            $dto->chunkHash->value
        );

        return $updatedSession;
    }
}
