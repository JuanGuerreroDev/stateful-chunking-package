<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\DTOs;

use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;

final class UploadChunkDTO
{
    public function __construct(
        public readonly SessionId $sessionId,
        public readonly int $chunkIndex,
        public readonly ChunkHash $chunkHash,
        public readonly string $content
    ) {}

    public static function fromArray(array $data, string $content): self
    {
        return new self(
            sessionId: SessionId::fromString((string) $data['session_id']),
            chunkIndex: (int) $data['chunk_index'],
            chunkHash: ChunkHash::fromString((string) $data['chunk_hash']),
            content: $content
        );
    }
}
