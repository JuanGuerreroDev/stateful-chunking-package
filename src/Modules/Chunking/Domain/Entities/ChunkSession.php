<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities;

use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Enums\SessionStatus;

final class ChunkSession
{
    /**
     * @param array<int, string> $chunksMap Status per chunk index (e.g. [0 => 'completed', 1 => 'pending'])
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
        public ?string $ownerId = null
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
