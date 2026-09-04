<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Modules\Chunking\Application\Actions;

use Juanoecr\StatefulChunking\Core\Contracts\StateRepositoryInterface;
use Juanoecr\StatefulChunking\Core\ValueObjects\SessionId;
use Juanoecr\StatefulChunking\Modules\Chunking\Application\DTOs\InitiateSessionDTO;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;
use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Events\ChunkSessionInitiated;

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

        ChunkSessionInitiated::dispatch($session);

        return $session;
    }
}
