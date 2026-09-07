<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities;

use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\ChunkIndexOutOfBoundsException;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Exceptions\UploadBudgetExceededException;

final class ChunkSession
{
    /**
     * @param  array<int, string>  $chunksMap  Status per chunk index (e.g. [0 => 'completed', 1 => 'pending'])
     */
    public function __construct(
        public readonly SessionId $sessionId,
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly int $totalChunks,
        public readonly ChunkHash $totalHash,
        public readonly string $fingerprint,
        public SessionStatus $status = SessionStatus::PENDING,
        public array $chunksMap = [],
        public int $createdAt = 0,
        public int $expiresAt = 0,
        public ?string $ownerId = null,
        public int $uploadedBytes = 0
    ) {
        if (empty($this->chunksMap)) {
            for ($i = 0; $i < $totalChunks; $i++) {
                $this->chunksMap[$i] = 'pending';
            }
        }

        $now = time();
        $this->createdAt = $this->createdAt > 0 ? $this->createdAt : $now;
        $this->expiresAt = $this->expiresAt > 0 ? $this->expiresAt : ($this->createdAt + 21600);
    }

    /**
     * Guard the aggregate's core invariant: a chunk index must fall within the
     * session's declared [0, totalChunks) range. Enforcing it on the root (rather
     * than in the calling Action) keeps invalid states unreachable no matter which
     * use case drives the session.
     */
    public function assertChunkIndexWithinBounds(int $chunkIndex): void
    {
        if ($chunkIndex < 0 || $chunkIndex >= $this->totalChunks) {
            throw new ChunkIndexOutOfBoundsException(
                sprintf('Chunk index %d out of bounds (totalChunks=%d).', $chunkIndex, $this->totalChunks),
                ['session_id' => $this->sessionId->value, 'chunk_index' => $chunkIndex, 'total_chunks' => $this->totalChunks]
            );
        }
    }

    /**
     * The maximum number of bytes this session may ever hold on disk, derived from
     * the declared file size plus one chunk of rounding slack, and never above the
     * configured hard cap. This is what turns file_size from a self-reported number
     * into an enforced limit.
     */
    public function byteBudget(int $chunkSizeBytes, int $maxFileSizeBytes): int
    {
        return min($maxFileSizeBytes, $this->fileSize + $chunkSizeBytes);
    }

    /**
     * Defense-in-depth for storage amplification: reject a chunk whose bytes would
     * push the session's cumulative upload past the budget its declared file_size
     * allows. Enforced on the aggregate root so no use case can bypass it.
     */
    public function assertWithinByteBudget(int $incomingBytes, int $chunkSizeBytes, int $maxFileSizeBytes): void
    {
        $budget = $this->byteBudget($chunkSizeBytes, $maxFileSizeBytes);

        if ($this->uploadedBytes + $incomingBytes > $budget) {
            throw new UploadBudgetExceededException(
                sprintf(
                    'Cumulative upload (%d + %d bytes) exceeds session budget of %d bytes.',
                    $this->uploadedBytes,
                    $incomingBytes,
                    $budget
                ),
                [
                    'session_id' => $this->sessionId->value,
                    'uploaded_bytes' => $this->uploadedBytes,
                    'incoming_bytes' => $incomingBytes,
                    'budget' => $budget,
                ]
            );
        }
    }

    public function recordUploadedBytes(int $bytes): void
    {
        $this->uploadedBytes += max(0, $bytes);
    }

    public function markChunkCompleted(int $chunkIndex): void
    {
        $this->chunksMap[$chunkIndex] = 'completed';
        $this->status = SessionStatus::UPLOADING;

        if ($this->isComplete()) {
            $this->status = SessionStatus::COMPLETED;
        }
    }

    public function markChunkFailed(int $chunkIndex): void
    {
        $this->chunksMap[$chunkIndex] = 'failed';
    }

    public function isComplete(): bool
    {
        foreach ($this->chunksMap as $status) {
            if ($status !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, int>
     */
    public function getPendingChunkIndices(): array
    {
        $pending = [];
        foreach ($this->chunksMap as $index => $status) {
            if ($status !== 'completed') {
                $pending[] = (int) $index;
            }
        }

        return $pending;
    }

    public function isExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    public function remainingTtl(): int
    {
        return max(0, $this->expiresAt - time());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId->value,
            'file_name' => $this->fileName,
            'file_size' => $this->fileSize,
            'uploaded_bytes' => $this->uploadedBytes,
            'total_chunks' => $this->totalChunks,
            'total_hash' => $this->totalHash->value,
            'fingerprint' => $this->fingerprint,
            'owner_id' => $this->ownerId,
            'status' => $this->status->value,
            'chunks_map' => $this->chunksMap,
            'pending_chunks' => $this->getPendingChunkIndices(),
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'is_expired' => $this->isExpired(),
            'remaining_ttl' => $this->remainingTtl(),
        ];
    }
}
