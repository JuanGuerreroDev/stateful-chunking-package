<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Entities;

use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Enums\SessionStatus;

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
        public array $chunksMap = []
    ) {
        if (empty($this->chunksMap)) {
            for ($i = 0; $i < $totalChunks; $i++) {
                $this->chunksMap[$i] = 'pending';
            }
        }
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
            'status' => $this->status->value,
            'chunks_map' => $this->chunksMap,
            'pending_chunks' => $this->getPendingChunkIndices(),
        ];
    }
}
