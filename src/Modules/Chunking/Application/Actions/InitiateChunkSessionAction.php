<?php

declare(strict_types=1);

namespace StatefulChunking\LaravelPackage\Modules\Chunking\Application\Actions;

use StatefulChunking\LaravelPackage\Core\Contracts\StateRepositoryInterface;
use StatefulChunking\LaravelPackage\Core\ValueObjects\SessionId;
use StatefulChunking\LaravelPackage\Modules\Chunking\Application\DTOs\InitiateSessionDTO;
use StatefulChunking\LaravelPackage\Modules\Chunking\Domain\Entities\ChunkSession;

final class InitiateChunkSessionAction
{
    public function __construct(
        private readonly StateRepositoryInterface $repository
    ) {}

    public function handle(InitiateSessionDTO $dto): ChunkSession
    {
        // Reuse session if fingerprint matches
        if (!empty($dto->fingerprint)) {
            $existing = $this->repository->findSessionByFingerprint($dto->fingerprint);
            if ($existing) {
                return $existing;
            }
        }

        $session = new ChunkSession(
            sessionId: SessionId::generate(),
            fileName: $dto->fileName,
            fileSize: $dto->fileSize,
            totalChunks: $dto->totalChunks,
            totalHash: $dto->totalHash,
            fingerprint: $dto->fingerprint
        );

        $this->repository->saveSession($session);

        return $session;
    }
}
