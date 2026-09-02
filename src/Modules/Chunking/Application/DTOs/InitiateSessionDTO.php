<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\DTOs;

use StatefulChunking\LaravelPackage\Core\ValueObjects\ChunkHash;

final class InitiateSessionDTO
{
    public function __construct(
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly int $totalChunks,
        public readonly ChunkHash $totalHash,
        public readonly string $fingerprint
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $fileName = isset($data['file_name']) && is_string($data['file_name']) ? $data['file_name'] : '';
        $fileSize = isset($data['file_size']) && is_numeric($data['file_size']) ? (int) $data['file_size'] : 0;
        $totalChunks = isset($data['total_chunks']) && is_numeric($data['total_chunks']) ? (int) $data['total_chunks'] : 0;
        $totalHash = isset($data['total_hash']) && is_string($data['total_hash']) ? $data['total_hash'] : '';
        $fingerprint = isset($data['fingerprint']) && is_string($data['fingerprint']) ? $data['fingerprint'] : '';

        return new self(
            fileName: $fileName,
            fileSize: $fileSize,
            totalChunks: $totalChunks,
            totalHash: ChunkHash::fromString($totalHash),
            fingerprint: $fingerprint
        );
    }
}
