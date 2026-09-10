<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunkingUpload\Core\Contracts\FileStorageInterface;
use Juanoecr\StatefulChunkingUpload\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Application\DTOs\UploadChunkDTO;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Events\ChunkUploaded;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\ChunkIntegrityException;
use Juanoecr\StatefulChunkingUpload\Modules\Chunking\Domain\Exceptions\SessionNotFoundException;

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

        // Defense-in-depth against storage amplification: reject the chunk before it
        // ever touches disk if it would push the session's cumulative upload past the
        // budget its declared file_size allows.
        //
        // This check reads a snapshot taken outside the lock, so it is an early exit
        // and not the guarantee. The budget travels with the state update below, where
        // it is verified again inside the critical section that increments the counter
        // — the only place the decision cannot be raced (AF-007).
        $chunkBytes = strlen($dto->content);
        $byteBudget = $session->byteBudget($this->chunkSizeBytes(), $this->maxFileSizeBytes());
        $session->assertWithinBudget($chunkBytes, $byteBudget);

        // Store chunk payload & validate chunk SHA-256
        $this->storage->storeChunk(
            sessionId: $dto->sessionId->value,
            chunkIndex: $dto->chunkIndex,
            content: $dto->content,
            chunkHash: $dto->chunkHash->value
        );

        try {
            // Update state in cache store, recording the chunk's bytes atomically.
            $this->repository->updateChunkStatus(
                sessionId: $dto->sessionId->value,
                chunkIndex: $dto->chunkIndex,
                status: 'completed',
                chunkBytes: $chunkBytes,
                byteBudget: $byteBudget
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

    private function chunkSizeBytes(): int
    {
        $raw = config('stateful-chunking-upload.chunk_size_bytes', 2097152);

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : 2097152;
    }

    private function maxFileSizeBytes(): int
    {
        $raw = config('stateful-chunking-upload.max_file_size_bytes', 10737418240);

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : 10737418240;
    }
}
