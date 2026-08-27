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

    public static function fromArray(array $data): self
    {
        return new self(
            fileName: (string) $data['file_name'],
            fileSize: (int) $data['file_size'],
            totalChunks: (int) $data['total_chunks'],
            totalHash: ChunkHash::fromString((string) $data['total_hash']),
            fingerprint: (string) ($data['fingerprint'] ?? '')
        );
    }
}
