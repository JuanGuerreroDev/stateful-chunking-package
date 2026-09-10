<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs;

use Juanoecr\StatefulChunking\Core\ValueObjects\ChunkHash;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionOwner;

final class InitiateSessionDTO
{
    public function __construct(
        public readonly string $fileName,
        public readonly int $fileSize,
        public readonly int $totalChunks,
        public readonly ChunkHash $totalHash,
        public readonly string $fingerprint,
        public readonly ?SessionOwner $ownerId = null
    ) {}

    /**
     * Build the DTO from a validated request payload.
     *
     * The owner arrives as an argument and never from $data. It used to fall back to
     * $data['owner_id'] when no owner was passed, which would have let a request body
     * name the owner of the session it was creating — an authorization value read from
     * the very party it authorizes. Nothing populated it, because owner_id is not a
     * validated field on any FormRequest, so the branch was unreachable rather than
     * exploitable; it is gone so it cannot become reachable by accident.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?SessionOwner $ownerId = null): self
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
            fingerprint: $fingerprint,
            ownerId: $ownerId
        );
    }
}
