<?php

declare(strict_types=1);

namespace Juanoecr\StatefulChunking\Core\Contracts;

use Juanoecr\StatefulChunking\Modules\Chunking\Domain\Entities\ChunkSession;

interface StateRepositoryInterface
{
    public function saveSession(ChunkSession $session): void;
    public function getSession(string $sessionId): ?ChunkSession;
    public function findSessionByFingerprint(string $fingerprint): ?ChunkSession;
    public function updateChunkStatus(string $sessionId, int $chunkIndex, string $status): void;
    public function deleteSession(string $sessionId): void;
}
