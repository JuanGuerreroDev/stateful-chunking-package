<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs;

use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;

final class UploadChunkDTO
{
    public function __construct(
        public readonly SessionId $sessionId,
        public readonly int $chunkIndex,
        public readonly ChunkHash $chunkHash,
        public readonly string $content
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $content): self
    {
        $sessionId = isset($data['session_id']) && is_string($data['session_id']) ? $data['session_id'] : '';
        $chunkIndex = isset($data['chunk_index']) && is_numeric($data['chunk_index']) ? (int) $data['chunk_index'] : 0;
        $chunkHash = isset($data['chunk_hash']) && is_string($data['chunk_hash']) ? $data['chunk_hash'] : '';

        return new self(
            sessionId: SessionId::fromString($sessionId),
            chunkIndex: $chunkIndex,
            chunkHash: ChunkHash::fromString($chunkHash),
            content: $content
        );
    }
}
